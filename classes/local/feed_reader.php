<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_rvc_enrolment_management\local;

/**
 * Reads an enrol plugin's external feed through that plugin's own connection and query code.
 *
 * The enrol plugin's settings are read on every call, never copied. Its protected db_init()
 * and db_get_sql() are reached by reflection so that the transport (ODBC for core database,
 * CoSector's own handling for the extended plugin) stays theirs to maintain. Anything the
 * driver prints, for example with the plugin's debugdb switched on, is discarded, and driver
 * errors are never passed on: a failure just makes the feed unavailable.
 *
 * @package   local_rvc_enrolment_management
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class feed_reader {

    /** @var string[] Enrol methods this plugin manages. */
    const METHODS = ['database', 'databaseextendedv2'];

    /** @var string[] Readable names for ADOdb drivers. */
    const TRANSPORTS = [
        'odbc_mssql' => 'ODBC (SQL Server)',
        'mssql' => 'SQL Server',
        'mssqlnative' => 'SQL Server',
        'mysqli' => 'MySQL',
        'postgres' => 'PostgreSQL',
        'postgres7' => 'PostgreSQL',
        'oci8po' => 'Oracle',
    ];

    /** @var string[] Course fields the enrol plugins can match on. */
    const COURSEFIELDS = ['id', 'idnumber', 'shortname'];

    /** @var string[] User fields the enrol plugins can match on. */
    const USERFIELDS = ['id', 'idnumber', 'email', 'username'];

    /** @var string Enrol method. */
    protected $method;

    /** @var \enrol_plugin|null The enrol plugin, or null when it is not installed. */
    protected $plugin;

    /**
     * Constructor.
     *
     * @param string $method one of self::METHODS
     */
    public function __construct(string $method) {
        if (!in_array($method, self::METHODS, true)) {
            throw new \coding_exception('Unsupported enrol method: ' . $method);
        }
        $this->method = $method;
        $this->plugin = enrol_get_plugin($method);
    }

    /**
     * Normalises a person key: no surrounding spaces, lower case.
     *
     * @param string $value
     * @return string
     */
    public static function normalise(string $value): string {
        return \core_text::strtolower(trim($value));
    }

    /**
     * The course field the enrol plugin matches on, or null if its setting is unusable.
     *
     * @return string|null
     */
    public function course_field(): ?string {
        $field = $this->plugin ? (string) $this->plugin->get_config('localcoursefield') : '';
        return in_array($field, self::COURSEFIELDS, true) ? $field : null;
    }

    /**
     * The user field the enrol plugin matches on, or null if its setting is unusable.
     *
     * @return string|null
     */
    public function user_field(): ?string {
        $field = $this->plugin ? (string) $this->plugin->get_config('localuserfield') : '';
        return in_array($field, self::USERFIELDS, true) ? $field : null;
    }

    /**
     * Names the feed by transport and database name, never by host, user or password.
     *
     * @return string for example "ODBC (SQL Server) · Moodle_Snapshot"
     */
    public function describe(): string {
        if (!$this->plugin) {
            return $this->method;
        }
        // The dbtype may be a full connection URL, so only the driver part before :// is used.
        $driver = explode('://', (string) $this->plugin->get_config('dbtype'), 2)[0];
        $label = self::TRANSPORTS[$driver] ?? $driver;
        $dbname = trim((string) $this->plugin->get_config('dbname'));
        return $dbname === '' ? $label : $label . ' · ' . $dbname;
    }

    /**
     * Reads who the feed lists for one course.
     *
     * The course is looked up by its key as stored; if that finds nothing and the key has
     * surrounding spaces, it is looked up again trimmed. An empty key cannot be listed in any
     * feed, so only the connection is checked.
     *
     * @param string $coursekey the course's value for the plugin's local course field
     * @return feed_result
     */
    public function read_course(string $coursekey): feed_result {
        if (!$this->plugin) {
            return feed_result::unavailable();
        }
        $table = trim((string) $this->plugin->get_config('remoteenroltable'));
        $coursefield = trim((string) $this->plugin->get_config('remotecoursefield'));
        $userfield = trim((string) $this->plugin->get_config('remoteuserfield'));
        if ($table === '' || $coursefield === '' || $userfield === '') {
            return feed_result::unavailable();
        }

        $level = ob_get_level();
        ob_start();
        try {
            $extdb = $this->call('db_init');
            if (!$extdb) {
                return feed_result::unavailable();
            }
            $rows = 0;
            $listed = [];
            foreach (array_unique([$coursekey, trim($coursekey)]) as $key) {
                if ($rows > 0 || trim($key) === '') {
                    continue;
                }
                $sql = $this->call('db_get_sql', $table, [$coursefield => $key], [$userfield]);
                $rs = $extdb->Execute($sql);
                if (!$rs) {
                    $extdb->Close();
                    return feed_result::unavailable();
                }
                while ($fields = $rs->FetchRow()) {
                    $fields = array_change_key_case($fields, CASE_LOWER);
                    $rows++;
                    $person = self::normalise((string) ($fields[strtolower($userfield)] ?? ''));
                    if ($person !== '') {
                        $listed[$person] = true;
                    }
                }
                $rs->Close();
            }
            $extdb->Close();
            return new feed_result(true, $rows, $listed);
        } catch (\Throwable $e) {
            return feed_result::unavailable();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    /**
     * Calls one of the enrol plugin's own methods, which may be protected.
     *
     * @param string $name method name
     * @param mixed ...$args
     * @return mixed
     */
    protected function call(string $name, ...$args) {
        $method = new \ReflectionMethod($this->plugin, $name);
        return $method->invoke($this->plugin, ...$args);
    }
}

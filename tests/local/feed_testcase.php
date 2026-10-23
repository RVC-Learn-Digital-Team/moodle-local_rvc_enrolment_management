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
 * Base for tests that read a feed through core enrol_database's own connection code.
 *
 * Like core's enrol_database tests, it points the plugin at a feed table in the test database,
 * which needs transactions off so the separate feed connection can see the rows.
 *
 * @package   local_rvc_enrolment_management
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class feed_testcase extends \advanced_testcase {
    /** @var string Feed table created in the test database. */
    const FEEDTABLE = 'local_rvcem_test_feed';

    /** @var string|false error_log setting to restore. */
    protected $oldlog;

    /** @var \enrol_plugin Core database enrol plugin. */
    protected $plugin;

    /**
     * Points core enrol_database at a feed table in the test database.
     */
    protected function setUp(): void {
        global $CFG, $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();

        // Discard ADOdb error logging, as core's enrol_database tests do.
        $this->oldlog = ini_get('error_log');
        ini_set('error_log', "$CFG->dataroot/testlog.log");

        set_config('dbencoding', 'utf-8', 'enrol_database');
        set_config('dbhost', $CFG->dbhost, 'enrol_database');
        set_config('dbuser', $CFG->dbuser, 'enrol_database');
        set_config('dbpass', $CFG->dbpass, 'enrol_database');
        set_config('dbname', $CFG->dbname, 'enrol_database');
        if (!empty($CFG->dboptions['dbport'])) {
            set_config('dbhost', $CFG->dbhost . ':' . $CFG->dboptions['dbport'], 'enrol_database');
        }
        switch ($DB->get_dbfamily()) {
            case 'mysql':
                set_config('dbtype', 'mysqli', 'enrol_database');
                set_config('dbsetupsql', "SET NAMES 'UTF-8'", 'enrol_database');
                set_config('dbsybasequoting', '0', 'enrol_database');
                break;
            case 'postgres':
                set_config('dbtype', 'postgres7', 'enrol_database');
                set_config('dbsetupsql', "SET NAMES 'UTF-8'", 'enrol_database');
                set_config('dbsybasequoting', '0', 'enrol_database');
                break;
            default:
                $this->markTestSkipped('Feed tests run on MySQL and PostgreSQL only.');
        }

        $dbman = $DB->get_manager();
        $table = new \xmldb_table(self::FEEDTABLE);
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_CHAR, '255', null, null, null);
        $table->add_field('userid', XMLDB_TYPE_CHAR, '255', null, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }
        $dbman->create_table($table);

        set_config('remoteenroltable', $CFG->prefix . self::FEEDTABLE, 'enrol_database');
        set_config('remotecoursefield', 'courseid', 'enrol_database');
        set_config('remoteuserfield', 'userid', 'enrol_database');
        set_config('localcoursefield', 'idnumber', 'enrol_database');
        set_config('localuserfield', 'username', 'enrol_database');
        set_config('debugdb', '0', 'enrol_database');

        $this->plugin = enrol_get_plugin('database');
    }

    /**
     * Drops the feed table and restores error logging.
     */
    protected function tearDown(): void {
        global $DB;
        $dbman = $DB->get_manager();
        $table = new \xmldb_table(self::FEEDTABLE);
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }
        ini_set('error_log', $this->oldlog);
        parent::tearDown();
    }

    /**
     * Adds a feed row.
     *
     * @param string $courseid
     * @param string $userid
     */
    protected function feed(string $courseid, string $userid): void {
        global $DB;
        $DB->insert_record(self::FEEDTABLE, (object) ['courseid' => $courseid, 'userid' => $userid]);
    }

    /**
     * Enrols a user through core database enrolment.
     *
     * @param \stdClass $course
     * @param \stdClass $user
     * @param int $status ENROL_USER_ACTIVE or ENROL_USER_SUSPENDED
     */
    protected function enrol_by_database(\stdClass $course, \stdClass $user, int $status = ENROL_USER_ACTIVE): void {
        global $DB;
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'database']);
        if (!$instance) {
            $instance = $DB->get_record('enrol', ['id' => $this->plugin->add_instance($course)]);
        }
        $studentrole = $DB->get_field('role', 'id', ['shortname' => 'student']);
        $this->plugin->enrol_user($instance, $user->id, $studentrole, 0, 0, $status);
    }

    /**
     * Usernames of a set of enrolment rows, sorted.
     *
     * @param \stdClass[] $enrolments
     * @return string[]
     */
    protected function usernames(array $enrolments): array {
        $names = array_map(fn($enrolment) => $enrolment->username, array_values($enrolments));
        sort($names);
        return $names;
    }
}

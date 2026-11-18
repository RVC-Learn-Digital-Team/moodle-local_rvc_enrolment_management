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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/csvlib.class.php');

/**
 * Reads an uploaded list of people for one course, and keeps it in the session.
 *
 * The file has a header row and two columns: one person column (userid or email) and one
 * course column (courseid, idnumber or shortname). Every row must name the same course.
 * The course page then shows only these people, exactly as it shows everyone otherwise.
 *
 * @package   local_rvc_enrolment_management
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upload {
    /** @var string[] Person columns and the user fields they match. */
    const USERCOLUMNS = ['userid' => 'id', 'email' => 'email'];

    /** @var string[] Course columns and the course fields they match. */
    const COURSECOLUMNS = ['courseid' => 'id', 'idnumber' => 'idnumber', 'shortname' => 'shortname'];

    /** @var string Session property holding recent uploads. */
    const SESSIONKEY = 'local_rvc_enrolment_management_uploads';

    /** @var int Uploads kept in the session. */
    const KEEP = 5;

    /**
     * Reads the file.
     *
     * @param string $content file content
     * @param string $encoding file encoding, for example UTF-8
     * @param string $delimiter delimiter name, for example comma
     * @return array [
     *     'error' => language string key when the whole file is refused, otherwise null,
     *     'course' => the course, when found,
     *     'userids' => user ids found, as keys,
     *     'problems' => rows that could not be used: ['line' => int, 'value' => string, 'reason' => string],
     * ]
     */
    public static function parse(string $content, string $encoding, string $delimiter): array {
        global $DB;
        $result = ['error' => null, 'course' => null, 'userids' => [], 'problems' => []];

        $iid = \csv_import_reader::get_new_iid('local_rvc_enrolment_management');
        $reader = new \csv_import_reader($iid, 'local_rvc_enrolment_management');
        $count = $reader->load_csv_content($content, $encoding, $delimiter);
        $columns = $reader->get_columns();
        if (!$count || !$columns || count($columns) !== 2) {
            $reader->cleanup();
            return ['error' => $count === false || !$columns ? 'upload_unreadable' : 'upload_badcolumns'] + $result;
        }
        $columns = array_map(fn($column) => \core_text::strtolower(trim($column)), $columns);
        $usercolumn = array_values(array_intersect($columns, array_keys(self::USERCOLUMNS)));
        $coursecolumn = array_values(array_intersect($columns, array_keys(self::COURSECOLUMNS)));
        if (count($usercolumn) !== 1 || count($coursecolumn) !== 1) {
            $reader->cleanup();
            return ['error' => 'upload_badcolumns'] + $result;
        }
        $userindex = array_search($usercolumn[0], $columns);
        $courseindex = array_search($coursecolumn[0], $columns);

        $rows = [];
        $courses = [];
        $reader->init();
        $line = 1;
        while ($values = $reader->next()) {
            $line++;
            $user = trim((string) ($values[$userindex] ?? ''));
            $coursevalue = trim((string) ($values[$courseindex] ?? ''));
            if ($user === '' || $coursevalue === '') {
                $result['problems'][] = ['line' => $line, 'value' => implode(', ', $values), 'reason' => 'unreadable'];
                continue;
            }
            $rows[$line] = $user;
            $courses[$coursevalue] = true;
        }
        $reader->close();
        $reader->cleanup();

        if (count($courses) > 1) {
            return ['error' => 'upload_multiplecourses'] + $result;
        }
        if (!$courses) {
            return ['error' => 'upload_emptyfile'] + $result;
        }
        $coursefield = self::COURSECOLUMNS[$coursecolumn[0]];
        $found = $DB->get_records('course', [$coursefield => array_key_first($courses)]);
        unset($found[SITEID]);
        if (count($found) !== 1) {
            return ['error' => 'upload_coursenotfound'] + $result;
        }
        $result['course'] = reset($found);

        $userfield = self::USERCOLUMNS[$usercolumn[0]];
        foreach ($rows as $line => $value) {
            if ($userfield === 'id') {
                $matches = ctype_digit($value) ? $DB->get_fieldset_select('user', 'id', 'id = ? AND deleted = 0', [$value]) : [];
            } else {
                $select = 'deleted = 0 AND ' . $DB->sql_equal('email', ':email', false);
                $matches = $DB->get_fieldset_select('user', 'id', $select, ['email' => $value]);
            }
            if (count($matches) === 1) {
                $result['userids'][(int) reset($matches)] = true;
            } else {
                $reason = $matches ? 'userambiguous' : 'usernotfound';
                $result['problems'][] = ['line' => $line, 'value' => $value, 'reason' => $reason];
            }
        }
        usort($result['problems'], fn($a, $b) => $a['line'] <=> $b['line']);
        return $result;
    }

    /**
     * Keeps a parsed upload in the session and returns its key.
     *
     * @param array $parsed from parse(), without an error
     * @param string $filename uploaded file name
     * @return string
     */
    public static function store(array $parsed, string $filename): string {
        global $SESSION;
        $key = random_string(12);
        $uploads = $SESSION->{self::SESSIONKEY} ?? [];
        $uploads[$key] = [
            'courseid' => (int) $parsed['course']->id,
            'userids' => array_keys($parsed['userids']),
            'problems' => $parsed['problems'],
            'filename' => $filename,
        ];
        $SESSION->{self::SESSIONKEY} = array_slice($uploads, -self::KEEP, null, true);
        return $key;
    }

    /**
     * A stored upload for the course, or null if it has gone or belongs to another course.
     *
     * @param string $key
     * @param int $courseid
     * @return array|null ['courseid' => int, 'userids' => int[], 'problems' => array, 'filename' => string]
     */
    public static function load(string $key, int $courseid): ?array {
        global $SESSION;
        $upload = $SESSION->{self::SESSIONKEY}[$key] ?? null;
        return ($upload && $upload['courseid'] === $courseid) ? $upload : null;
    }
}

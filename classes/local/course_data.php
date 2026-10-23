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
 * Moodle-side facts the course page shows beside the feed review.
 *
 * @package   local_rvc_enrolment_management
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_data {
    /** @var string Suspension record table. */
    const TABLE = 'local_rvc_enrolment_management_suspension';

    /**
     * Managed methods that have an instance in the course.
     *
     * @param int $courseid
     * @return string[]
     */
    public static function methods(int $courseid): array {
        global $DB;
        $present = $DB->get_fieldset_select('enrol', 'DISTINCT enrol', 'courseid = ?', [$courseid]);
        return array_values(array_intersect(feed_reader::METHODS, $present));
    }

    /**
     * Role names each person holds in the course, as text.
     *
     * @param \context_course $context
     * @return string[] keyed by user id
     */
    public static function roles(\context_course $context): array {
        global $DB;
        $names = role_get_names($context, ROLENAME_ALIAS, true);
        $sql = "SELECT ra.id, ra.userid, ra.roleid
                  FROM {role_assignments} ra
                 WHERE ra.contextid = :contextid
              ORDER BY ra.roleid";
        $roles = [];
        foreach ($DB->get_records_sql($sql, ['contextid' => $context->id]) as $assignment) {
            $roles[$assignment->userid][$assignment->roleid] = $names[$assignment->roleid] ?? '';
        }
        return array_map(fn($userroles) => implode(', ', $userroles), $roles);
    }

    /**
     * When each person last accessed the course.
     *
     * @param int $courseid
     * @return int[] timestamps keyed by user id
     */
    public static function last_access(int $courseid): array {
        global $DB;
        return $DB->get_records_menu('user_lastaccess', ['courseid' => $courseid], '', 'userid, timeaccess');
    }

    /**
     * When the method's sync task last ran, or 0.
     *
     * @param string $method
     * @return int
     */
    public static function last_sync(string $method): int {
        global $DB;
        $classname = '\\enrol_' . $method . '\\task\\sync_enrolments';
        return (int) $DB->get_field('task_scheduled', 'lastruntime', ['classname' => $classname]);
    }

    /**
     * Suspended enrolments by one method, split into those this tool suspended and the rest.
     *
     * Tool rows carry the suspension record as timesuspended, suspendedby, reason and the
     * suspending administrator's name fields prefixed "admin".
     *
     * @param int $courseid
     * @param string $method
     * @return array ['tool' => \stdClass[], 'elsewhere' => \stdClass[]], each keyed by user enrolment id
     */
    public static function suspended(int $courseid, string $method): array {
        global $DB;
        $userfields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $adminfields = \core_user\fields::for_name()->get_sql('a', false, 'admin', '', false)->selects;
        $sql = "SELECT ue.id, ue.userid, ue.enrolid, ue.timemodified, u.username, $userfields,
                       s.id AS suspensionid, s.timesuspended, s.suspendedby, s.reason, $adminfields
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {user} u ON u.id = ue.userid
             LEFT JOIN {" . self::TABLE . "} s ON s.userenrolmentid = ue.id AND s.timereactivated = 0 AND s.timedeleted = 0
             LEFT JOIN {user} a ON a.id = s.suspendedby
                 WHERE e.courseid = :courseid AND e.enrol = :method AND ue.status = :suspended
              ORDER BY u.lastname, u.firstname, ue.id";
        $split = ['tool' => [], 'elsewhere' => []];
        $params = ['courseid' => $courseid, 'method' => $method, 'suspended' => ENROL_USER_SUSPENDED];
        foreach ($DB->get_records_sql($sql, $params) as $id => $row) {
            $split[$row->suspensionid ? 'tool' : 'elsewhere'][$id] = $row;
        }
        return $split;
    }
}

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

namespace local_rvc_enrolment_management\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for the suspension record.
 *
 * A person is either the subject of a suspension (userid) or the administrator who acted
 * (suspendedby, reactivatedby, deletedby). Deleting a person's data removes the rows about
 * them and blanks their name from rows about other people, so those rows stay intact.
 *
 * @package   local_rvc_enrolment_management
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /** @var string Suspension record table. */
    const TABLE = 'local_rvc_enrolment_management_suspension';

    /** @var string[] Fields that hold an administrator's user id. */
    const ACTORFIELDS = ['suspendedby', 'reactivatedby', 'deletedby'];

    /**
     * Describes the personal data this plugin stores.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(self::TABLE, [
            'userid' => 'privacy:metadata:suspension:userid',
            'courseid' => 'privacy:metadata:suspension:courseid',
            'enrol' => 'privacy:metadata:suspension:enrol',
            'timesuspended' => 'privacy:metadata:suspension:timesuspended',
            'suspendedby' => 'privacy:metadata:suspension:suspendedby',
            'reason' => 'privacy:metadata:suspension:reason',
            'timereactivated' => 'privacy:metadata:suspension:timereactivated',
            'reactivatedby' => 'privacy:metadata:suspension:reactivatedby',
            'timedeleted' => 'privacy:metadata:suspension:timedeleted',
            'deletedby' => 'privacy:metadata:suspension:deletedby',
        ], 'privacy:metadata:suspension');
        return $collection;
    }

    /**
     * Course contexts holding rows about, or made by, the user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        [$where, $params] = self::user_condition($userid, 's.');
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {" . self::TABLE . "} s ON s.courseid = ctx.instanceid AND ctx.contextlevel = :courselevel
                 WHERE $where";
        $contextlist = new contextlist();
        $contextlist->add_from_sql($sql, $params + ['courselevel' => CONTEXT_COURSE]);
        return $contextlist;
    }

    /**
     * Users with rows in a course context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_course) {
            return;
        }
        foreach (array_merge(['userid'], self::ACTORFIELDS) as $field) {
            $sql = "SELECT $field FROM {" . self::TABLE . "} WHERE courseid = :courseid AND $field <> 0";
            $userlist->add_from_sql($field, $sql, ['courseid' => $context->instanceid]);
        }
    }

    /**
     * Exports the rows about, or made by, the user.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;
        [$where, $params] = self::user_condition($userid);
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_course) {
                continue;
            }
            $records = $DB->get_records_select(self::TABLE, "courseid = :courseid AND ($where)",
                $params + ['courseid' => $context->instanceid], 'timesuspended, id');
            if (!$records) {
                continue;
            }
            $suspensions = [];
            foreach ($records as $record) {
                $suspensions[] = (object) [
                    'enrol' => $record->enrol,
                    'aboutyou' => transform::yesno($record->userid == $userid),
                    'timesuspended' => transform::datetime($record->timesuspended),
                    'suspendedbyyou' => transform::yesno($record->suspendedby == $userid),
                    'reason' => $record->reason,
                    'timereactivated' => $record->timereactivated ? transform::datetime($record->timereactivated) : null,
                    'reactivatedbyyou' => transform::yesno($record->reactivatedby == $userid),
                    'timedeleted' => $record->timedeleted ? transform::datetime($record->timedeleted) : null,
                    'deletedbyyou' => transform::yesno($record->deletedby == $userid),
                ];
            }
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_rvc_enrolment_management')],
                (object) ['suspensions' => $suspensions]
            );
        }
    }

    /**
     * Deletes every row for a course context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if ($context instanceof \context_course) {
            $DB->delete_records(self::TABLE, ['courseid' => $context->instanceid]);
        }
    }

    /**
     * Deletes one user's data in the approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_course) {
                self::delete_user_in_course($userid, (int) $context->instanceid);
            }
        }
    }

    /**
     * Deletes several users' data in one course context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_course) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            self::delete_user_in_course((int) $userid, (int) $context->instanceid);
        }
    }

    /**
     * Removes rows about the user and blanks them as the acting administrator.
     *
     * @param int $userid
     * @param int $courseid
     */
    protected static function delete_user_in_course(int $userid, int $courseid): void {
        global $DB;

        $DB->delete_records(self::TABLE, ['courseid' => $courseid, 'userid' => $userid]);
        foreach (self::ACTORFIELDS as $field) {
            $DB->set_field(self::TABLE, $field, 0, ['courseid' => $courseid, $field => $userid]);
        }
    }

    /**
     * SQL condition matching rows about, or made by, the user.
     *
     * @param int $userid
     * @param string $alias table alias with its dot, or empty
     * @return array [sql, params]
     */
    protected static function user_condition(int $userid, string $alias = ''): array {
        $conditions = ["{$alias}userid = :subjectid"];
        $params = ['subjectid' => $userid];
        foreach (self::ACTORFIELDS as $field) {
            $conditions[] = "{$alias}{$field} = :$field";
            $params[$field] = $userid;
        }
        return [implode(' OR ', $conditions), $params];
    }
}

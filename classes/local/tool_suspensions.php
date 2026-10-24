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
 * Re-activates or deletes suspended enrolments.
 *
 * Suspensions this tool made can be re-activated or deleted; enrolments suspended by
 * something else (for example the extended plugin's own sync) can be deleted. Deletion needs
 * the enrolment to be still suspended and the feed to be readable and still not list the
 * person. It also waits the configured number of days after the suspension: this tool's
 * record gives that date, and otherwise the enrolment's last change is used. A last change in
 * the future, or older than the waiting period, does not hold deletion back. Every check is
 * repeated at the moment of acting, and each deletion is recorded with an optional reason.
 *
 * @package   local_rvc_enrolment_management
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_suspensions {
    /** @var string Action: re-activate a suspension this tool made. */
    const REACTIVATE = 'reactivate';

    /** @var string Action: delete an enrolment this tool suspended. */
    const DELETE = 'delete';

    /** @var string Action: delete an enrolment suspended by something else. */
    const DELETE_ELSEWHERE = 'deleteelsewhere';

    /** @var string Skip reason: not an enrolment that this action applies to in this course. */
    const SKIP_NOTFOUND = 'notfound';

    /** @var string Skip reason: the enrolment is no longer suspended. */
    const SKIP_NOTSUSPENDED = 'notsuspended';

    /** @var string Skip reason: the deletion delay has not passed. */
    const SKIP_TOOEARLY = 'tooearly';

    /** @var string Skip reason: the feed lists the person again. */
    const SKIP_LISTED = 'listed';

    /** @var string Skip reason: the method's feed could not be read. */
    const SKIP_FEEDUNAVAILABLE = 'feedunavailable';

    /**
     * When an enrolment suspended at a given time can be deleted, or 0 if it can be now.
     *
     * @param int $suspended time of the suspension, or of the enrolment's last change
     * @return int
     */
    public static function deletable_from(int $suspended): int {
        $delay = (int) get_config('local_rvc_enrolment_management', 'deletiondelaydays') * DAYSECS;
        $now = time();
        return ($suspended <= $now && $suspended > $now - $delay) ? $suspended + $delay : 0;
    }

    /**
     * Checks the selection for an action now.
     *
     * For REACTIVATE and DELETE the ids are this tool's suspension record ids; for
     * DELETE_ELSEWHERE they are user enrolment ids. Eligible rows carry the person's name
     * fields, ->enrol, ->enrolid, ->userid, ->userenrolmentid and, for deletions,
     * ->lastenrolment (true when it is the person's only enrolment in the course) and
     * ->courseinfeed.
     *
     * @param string $action
     * @param \stdClass $course
     * @param int[] $ids
     * @return array ['eligible' => rows keyed by id, 'skipped' => skip reasons keyed by id]
     */
    public static function check(string $action, \stdClass $course, array $ids): array {
        $ids = array_unique(array_map('intval', $ids));
        $result = ['eligible' => [], 'skipped' => []];
        if (!$ids) {
            return $result;
        }
        $rows = $action === self::DELETE_ELSEWHERE ? self::elsewhere_rows($course, $ids) : self::tool_rows($course, $ids);
        $feeds = [];
        foreach ($ids as $id) {
            $row = $rows[$id] ?? null;
            if (!$row) {
                $result['skipped'][$id] = self::SKIP_NOTFOUND;
                continue;
            }
            if ((int) $row->status !== ENROL_USER_SUSPENDED) {
                $result['skipped'][$id] = self::SKIP_NOTSUSPENDED;
                continue;
            }
            if ($action !== self::REACTIVATE) {
                $skip = self::deletion_skip($course, $row, $feeds);
                if ($skip) {
                    $result['skipped'][$id] = $skip;
                    continue;
                }
            }
            $result['eligible'][$id] = $row;
        }
        return $result;
    }

    /**
     * Checks again, then re-activates this tool's suspensions and records who did it.
     *
     * @param \stdClass $course
     * @param int[] $ids suspension record ids
     * @return array ['done' => rows keyed by id, 'skipped' => skip reasons keyed by id]
     */
    public static function reactivate(\stdClass $course, array $ids): array {
        global $DB, $USER;
        $check = self::check(self::REACTIVATE, $course, $ids);
        foreach ($check['eligible'] as $id => $row) {
            $transaction = $DB->start_delegated_transaction();
            enrol_get_plugin($row->enrol)->update_user_enrol(self::instance($row), $row->userid, ENROL_USER_ACTIVE);
            $stamp = ['id' => $id, 'timereactivated' => time(), 'reactivatedby' => $USER->id];
            $DB->update_record(course_data::TABLE, (object) $stamp);
            $transaction->allow_commit();
        }
        return ['done' => $check['eligible'], 'skipped' => $check['skipped']];
    }

    /**
     * Checks again, then deletes the enrolments and records each deletion.
     *
     * @param string $action self::DELETE (suspension record ids) or self::DELETE_ELSEWHERE (user enrolment ids)
     * @param \stdClass $course
     * @param int[] $ids
     * @param string $reason optional reason, stored with each deletion
     * @return array ['done' => rows keyed by id, 'skipped' => skip reasons keyed by id]
     */
    public static function delete(string $action, \stdClass $course, array $ids, string $reason): array {
        global $DB, $USER;
        $check = self::check($action, $course, $ids);
        $now = time();
        foreach ($check['eligible'] as $id => $row) {
            $transaction = $DB->start_delegated_transaction();
            enrol_get_plugin($row->enrol)->unenrol_user(self::instance($row), $row->userid);
            $stamp = ['timedeleted' => $now, 'deletedby' => $USER->id, 'deletereason' => $reason === '' ? null : $reason];
            if ($action === self::DELETE) {
                $DB->update_record(course_data::TABLE, (object) (['id' => $id] + $stamp));
            } else {
                // Suspended elsewhere: record the deletion, with the enrolment's last change as the
                // suspension date and no suspending administrator.
                $DB->insert_record(course_data::TABLE, (object) ($stamp + [
                    'courseid' => $course->id,
                    'userid' => $row->userid,
                    'userenrolmentid' => $row->userenrolmentid,
                    'enrolid' => $row->enrolid,
                    'enrol' => $row->enrol,
                    'timesuspended' => $row->timemodified,
                    'suspendedby' => 0,
                    'courseinfeed' => $row->courseinfeed ? 1 : 0,
                ]));
            }
            $transaction->allow_commit();
        }
        return ['done' => $check['eligible'], 'skipped' => $check['skipped']];
    }

    /**
     * Open suspension records by this tool in the course, keyed by record id.
     *
     * @param \stdClass $course
     * @param int[] $ids
     * @return \stdClass[]
     */
    protected static function tool_rows(\stdClass $course, array $ids): array {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $sql = "SELECT s.id, s.userid, s.userenrolmentid, s.enrolid, s.enrol, s.timesuspended, ue.status, " . self::USERSQL . "
                  FROM {" . course_data::TABLE . "} s
                  JOIN {user} u ON u.id = s.userid
             LEFT JOIN {user_enrolments} ue ON ue.id = s.userenrolmentid
                 WHERE s.id $insql AND s.courseid = :courseid AND s.timereactivated = 0 AND s.timedeleted = 0";
        return self::with_names($DB->get_records_sql($sql, $params + ['courseid' => $course->id]));
    }

    /**
     * Database-family enrolments in the course with no open suspension by this tool, keyed by
     * user enrolment id. Their last change stands in for the suspension date.
     *
     * @param \stdClass $course
     * @param int[] $ids
     * @return \stdClass[]
     */
    protected static function elsewhere_rows(\stdClass $course, array $ids): array {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        [$methodsql, $methodparams] = $DB->get_in_or_equal(feed_reader::METHODS, SQL_PARAMS_NAMED, 'method');
        $sql = "SELECT ue.id, ue.userid, ue.id AS userenrolmentid, ue.enrolid, e.enrol, ue.timemodified,
                       ue.timemodified AS timesuspended, ue.status, " . self::USERSQL . "
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {user} u ON u.id = ue.userid
             LEFT JOIN {" . course_data::TABLE . "} s
                       ON s.userenrolmentid = ue.id AND s.timereactivated = 0 AND s.timedeleted = 0
                 WHERE ue.id $insql AND e.courseid = :courseid AND e.enrol $methodsql AND s.id IS NULL";
        $params += $methodparams + ['courseid' => $course->id];
        return self::with_names($DB->get_records_sql($sql, $params));
    }

    /** @var string Person columns selected with each row; name fields are added by with_names(). */
    const USERSQL = 'u.username, u.email, u.idnumber AS useridnumber';

    /**
     * Adds the person's name fields to each row.
     *
     * @param \stdClass[] $rows
     * @return \stdClass[]
     */
    protected static function with_names(array $rows): array {
        global $DB;
        if (!$rows) {
            return [];
        }
        $userids = array_unique(array_column($rows, 'userid'));
        $fields = \core_user\fields::for_name()->get_sql('', false, '', '', false)->selects;
        $users = $DB->get_records_list('user', 'id', $userids, '', 'id, ' . $fields);
        foreach ($rows as $row) {
            foreach ((array) $users[$row->userid] as $name => $value) {
                if ($name !== 'id') {
                    $row->$name = $value;
                }
            }
        }
        return $rows;
    }

    /**
     * Why a suspended row can't be deleted now, or null if it can. Sets ->lastenrolment and
     * ->courseinfeed on rows that can.
     *
     * @param \stdClass $course
     * @param \stdClass $row
     * @param array $feeds feeds already read, keyed by method; updated
     * @return string|null skip reason
     */
    protected static function deletion_skip(\stdClass $course, \stdClass $row, array &$feeds): ?string {
        global $DB;
        if (self::deletable_from((int) $row->timesuspended)) {
            return self::SKIP_TOOEARLY;
        }
        $feeds[$row->enrol] = $feeds[$row->enrol] ?? self::read_feed($course, $row->enrol);
        [$reader, $feed] = $feeds[$row->enrol];
        if (!$feed->available) {
            return self::SKIP_FEEDUNAVAILABLE;
        }
        $userfield = $reader->user_field();
        $userkey = ['id' => $row->userid, 'idnumber' => $row->useridnumber][$userfield] ?? $row->$userfield;
        if ($feed->lists((string) $userkey)) {
            return self::SKIP_LISTED;
        }
        $sql = "SELECT COUNT(1) FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE e.courseid = ? AND ue.userid = ? AND ue.id <> ?";
        $row->lastenrolment = !$DB->count_records_sql($sql, [$course->id, $row->userid, $row->userenrolmentid]);
        $row->courseinfeed = $feed->course_in_feed();
        return null;
    }

    /**
     * The enrol instance of a row.
     *
     * @param \stdClass $row
     * @return \stdClass
     */
    protected static function instance(\stdClass $row): \stdClass {
        global $DB;
        return $DB->get_record('enrol', ['id' => $row->enrolid], '*', MUST_EXIST);
    }

    /**
     * Reads one method's feed for the course.
     *
     * @param \stdClass $course
     * @param string $method
     * @return array [feed_reader, feed_result]
     */
    protected static function read_feed(\stdClass $course, string $method): array {
        $reader = new feed_reader($method);
        $coursefield = $reader->course_field();
        $feed = ($coursefield && $reader->user_field())
            ? $reader->read_course((string) $course->$coursefield)
            : feed_result::unavailable();
        return [$reader, $feed];
    }
}

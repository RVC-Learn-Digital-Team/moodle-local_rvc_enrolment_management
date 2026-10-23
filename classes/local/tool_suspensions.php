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
 * Re-activates or deletes enrolments that this tool suspended.
 *
 * Only suspensions this tool made, and that are not already re-activated or deleted, can be
 * acted on. Deletion also needs the configured number of days to have passed, the enrolment
 * to be still suspended, and the feed to be readable and still not list the person. Every
 * check is repeated at the moment of acting.
 *
 * @package   local_rvc_enrolment_management
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_suspensions {
    /** @var string Action: re-activate. */
    const REACTIVATE = 'reactivate';

    /** @var string Action: delete. */
    const DELETE = 'delete';

    /** @var string Skip reason: not an open suspension by this tool in this course. */
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
     * Checks the selected suspension records for an action now.
     *
     * Eligible rows are suspension records with the person's name fields, ->status of the
     * enrolment and, for deletion, ->lastenrolment (true when it is the person's only
     * enrolment in the course).
     *
     * @param string $action self::REACTIVATE or self::DELETE
     * @param \stdClass $course
     * @param int[] $ids suspension record ids
     * @return array ['eligible' => rows keyed by record id, 'skipped' => skip reasons keyed by record id]
     */
    public static function check(string $action, \stdClass $course, array $ids): array {
        global $DB;
        $ids = array_unique(array_map('intval', $ids));
        $result = ['eligible' => [], 'skipped' => []];
        if (!$ids) {
            return $result;
        }
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $userfields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $sql = "SELECT s.*, ue.status, u.username, u.email, u.idnumber AS useridnumber, $userfields
                  FROM {" . course_data::TABLE . "} s
                  JOIN {user} u ON u.id = s.userid
             LEFT JOIN {user_enrolments} ue ON ue.id = s.userenrolmentid
                 WHERE s.id $insql AND s.courseid = :courseid AND s.timereactivated = 0 AND s.timedeleted = 0";
        $rows = $DB->get_records_sql($sql, $params + ['courseid' => $course->id]);

        $delay = (int) get_config('local_rvc_enrolment_management', 'deletiondelaydays') * DAYSECS;
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
            if ($action === self::DELETE) {
                if ($row->timesuspended + $delay > time()) {
                    $result['skipped'][$id] = self::SKIP_TOOEARLY;
                    continue;
                }
                $feeds[$row->enrol] = $feeds[$row->enrol] ?? self::read_feed($course, $row->enrol);
                [$reader, $feed] = $feeds[$row->enrol];
                if (!$feed->available) {
                    $result['skipped'][$id] = self::SKIP_FEEDUNAVAILABLE;
                    continue;
                }
                $userkey = ['id' => $row->userid, 'idnumber' => $row->useridnumber][$reader->user_field()]
                    ?? $row->{$reader->user_field()};
                if ($feed->lists((string) $userkey)) {
                    $result['skipped'][$id] = self::SKIP_LISTED;
                    continue;
                }
                $sql = "SELECT COUNT(1) FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
                         WHERE e.courseid = ? AND ue.userid = ? AND ue.id <> ?";
                $row->lastenrolment = !$DB->count_records_sql($sql, [$course->id, $row->userid, $row->userenrolmentid]);
            }
            $result['eligible'][$id] = $row;
        }
        return $result;
    }

    /**
     * Checks again, then re-activates the eligible enrolments and records who did it.
     *
     * @param \stdClass $course
     * @param int[] $ids suspension record ids
     * @return array ['done' => rows keyed by record id, 'skipped' => skip reasons keyed by record id]
     */
    public static function reactivate(\stdClass $course, array $ids): array {
        return self::act(self::REACTIVATE, $course, $ids, function (\enrol_plugin $plugin, \stdClass $instance, \stdClass $row) {
            $plugin->update_user_enrol($instance, $row->userid, ENROL_USER_ACTIVE);
        });
    }

    /**
     * Checks again, then deletes the eligible enrolments and records who did it.
     *
     * @param \stdClass $course
     * @param int[] $ids suspension record ids
     * @return array ['done' => rows keyed by record id, 'skipped' => skip reasons keyed by record id]
     */
    public static function delete(\stdClass $course, array $ids): array {
        return self::act(self::DELETE, $course, $ids, function (\enrol_plugin $plugin, \stdClass $instance, \stdClass $row) {
            $plugin->unenrol_user($instance, $row->userid);
        });
    }

    /**
     * Runs an action on the eligible records and stamps each record.
     *
     * @param string $action
     * @param \stdClass $course
     * @param int[] $ids
     * @param callable $change function (enrol_plugin, instance, row) making the enrolment change
     * @return array ['done' => rows keyed by record id, 'skipped' => skip reasons keyed by record id]
     */
    protected static function act(string $action, \stdClass $course, array $ids, callable $change): array {
        global $DB, $USER;
        $check = self::check($action, $course, $ids);
        $field = $action === self::DELETE ? 'deleted' : 'reactivated';
        foreach ($check['eligible'] as $id => $row) {
            $instance = $DB->get_record('enrol', ['id' => $row->enrolid], '*', MUST_EXIST);
            $transaction = $DB->start_delegated_transaction();
            $change(enrol_get_plugin($row->enrol), $instance, $row);
            $DB->update_record(course_data::TABLE, (object) [
                'id' => $id,
                'time' . $field => time(),
                $field . 'by' => $USER->id,
            ]);
            $transaction->allow_commit();
        }
        return ['done' => $check['eligible'], 'skipped' => $check['skipped']];
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

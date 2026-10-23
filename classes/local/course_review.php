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
 * Splits one course's active enrolments for one method by whether the method's feed lists them.
 *
 * Only being listed stops a suspension. Neither the course nor the person needs to be in the
 * feed. When the feed cannot be read, nobody is classified, so nothing can be suspended.
 *
 * @package   local_rvc_enrolment_management
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_review {
    /** @var feed_result What the feed said about the course. */
    public $feed;

    /** @var \stdClass[] Every active enrolment by the method, keyed by user enrolment id. */
    public $active = [];

    /** @var \stdClass[] Active enrolments the feed does not list: these can be suspended. */
    public $notlisted = [];

    /** @var \stdClass[] Active enrolments the feed still lists: these cannot be suspended. */
    public $listed = [];

    /**
     * Reviews one course for one enrol method.
     *
     * Each enrolment row carries the person's name fields, username, email, matchvalue (their
     * value for the plugin's local user field), usersuspended, userdeleted and otheractive
     * (methods of their other active enrolments in the course).
     *
     * @param \stdClass $course course record
     * @param string $method one of feed_reader::METHODS
     * @param feed_reader|null $reader feed reader to use; one for $method by default
     * @return self
     */
    public static function review(\stdClass $course, string $method, ?feed_reader $reader = null): self {
        $reader = $reader ?? new feed_reader($method);
        $review = new self();

        $coursefield = $reader->course_field();
        $userfield = $reader->user_field();
        $review->feed = ($coursefield && $userfield)
            ? $reader->read_course((string) $course->$coursefield)
            : feed_result::unavailable();

        $review->active = self::active_enrolments((int) $course->id, $method, $userfield ?? 'username');
        if (!$review->feed->available) {
            return $review;
        }
        foreach ($review->active as $ueid => $enrolment) {
            if ($review->feed->lists((string) $enrolment->matchvalue)) {
                $review->listed[$ueid] = $enrolment;
            } else {
                $review->notlisted[$ueid] = $enrolment;
            }
        }
        return $review;
    }

    /**
     * Active enrolments by one method in a course, with each person's other active enrolments.
     *
     * @param int $courseid
     * @param string $method enrol method
     * @param string $userfield validated local user field
     * @return \stdClass[] keyed by user enrolment id
     */
    protected static function active_enrolments(int $courseid, string $method, string $userfield): array {
        global $DB;

        $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $sql = "SELECT ue.id, ue.userid, ue.enrolid, ue.timestart, ue.timeend, ue.timecreated,
                       u.username, u.email, u.suspended AS usersuspended, u.deleted AS userdeleted,
                       u.$userfield AS matchvalue, $namefields
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {user} u ON u.id = ue.userid
                 WHERE e.courseid = :courseid AND e.enrol = :method AND ue.status = :active
              ORDER BY u.lastname, u.firstname, ue.id";
        $enrolments = $DB->get_records_sql($sql, [
            'courseid' => $courseid,
            'method' => $method,
            'active' => ENROL_USER_ACTIVE,
        ]);

        $now = time();
        $sql = "SELECT ue.id, ue.userid, e.enrol
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE e.courseid = :courseid AND e.status = :enabled AND ue.status = :active
                       AND ue.timestart <= :now1 AND (ue.timeend = 0 OR ue.timeend > :now2)";
        $others = $DB->get_records_sql($sql, [
            'courseid' => $courseid,
            'enabled' => ENROL_INSTANCE_ENABLED,
            'active' => ENROL_USER_ACTIVE,
            'now1' => $now,
            'now2' => $now,
        ]);

        foreach ($enrolments as $enrolment) {
            $enrolment->otheractive = [];
            foreach ($others as $other) {
                if ($other->userid == $enrolment->userid && $other->id != $enrolment->id) {
                    $enrolment->otheractive[] = $other->enrol;
                }
            }
        }
        return $enrolments;
    }
}

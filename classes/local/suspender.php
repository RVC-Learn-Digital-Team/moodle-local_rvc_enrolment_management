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
 * Checks a selection of enrolments against the feeds and suspends those still eligible.
 *
 * The check is repeated at the moment of suspension, so someone added back to the feed after
 * the course page was shown is skipped. Only status changes: roles and groups are kept.
 *
 * @package   local_rvc_enrolment_management
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class suspender {
    /** @var string Skip reason: the feed now lists the person. */
    const SKIP_LISTED = 'listed';

    /** @var string Skip reason: the method's feed could not be read. */
    const SKIP_FEEDUNAVAILABLE = 'feedunavailable';

    /** @var string Skip reason: not an active database-family enrolment in this course. */
    const SKIP_NOTACTIVE = 'notactive';

    /**
     * Checks the selected enrolments against the feeds now.
     *
     * @param \stdClass $course
     * @param int[] $ueids selected user enrolment ids
     * @return array [
     *     'eligible' => enrolment rows keyed by id, each with ->method,
     *     'skipped' => skip reasons keyed by id,
     *     'counts' => ['method' => ['selected' => int, 'active' => int]],
     *     'courseinfeed' => ['method' => bool],
     * ]
     */
    public static function check(\stdClass $course, array $ueids): array {
        $remaining = array_fill_keys(array_unique(array_map('intval', $ueids)), true);
        $result = ['eligible' => [], 'skipped' => [], 'counts' => [], 'courseinfeed' => []];
        foreach (course_data::methods((int) $course->id) as $method) {
            $review = course_review::review($course, $method);
            $result['courseinfeed'][$method] = $review->feed->course_in_feed();
            $selected = 0;
            foreach (array_keys($remaining) as $ueid) {
                if (isset($review->notlisted[$ueid])) {
                    $row = $review->notlisted[$ueid];
                    $row->method = $method;
                    $result['eligible'][$ueid] = $row;
                    $selected++;
                } else if (isset($review->listed[$ueid])) {
                    $result['skipped'][$ueid] = self::SKIP_LISTED;
                } else if (isset($review->active[$ueid])) {
                    $result['skipped'][$ueid] = self::SKIP_FEEDUNAVAILABLE;
                } else {
                    continue;
                }
                unset($remaining[$ueid]);
            }
            $result['counts'][$method] = ['selected' => $selected, 'active' => count($review->active)];
        }
        foreach (array_keys($remaining) as $ueid) {
            $result['skipped'][$ueid] = self::SKIP_NOTACTIVE;
        }
        return $result;
    }

    /**
     * Methods whose selection is over the warning threshold of their active enrolments.
     *
     * @param array $counts counts from check()
     * @return array ['method' => ['selected' => int, 'active' => int, 'percent' => int]]
     */
    public static function warnings(array $counts): array {
        $threshold = (int) get_config('local_rvc_enrolment_management', 'warnthreshold');
        $warnings = [];
        foreach ($counts as $method => $count) {
            if ($count['active'] && $count['selected']) {
                $percent = (int) round(100 * $count['selected'] / $count['active']);
                if ($percent > $threshold) {
                    $warnings[$method] = $count + ['percent' => $percent];
                }
            }
        }
        return $warnings;
    }

    /**
     * Checks again, then suspends the eligible enrolments and records each suspension.
     *
     * @param \stdClass $course
     * @param int[] $ueids selected user enrolment ids
     * @param string $reason optional reason, stored with each suspension
     * @return array ['suspended' => enrolment rows keyed by id, 'skipped' => skip reasons keyed by id]
     */
    public static function suspend(\stdClass $course, array $ueids, string $reason): array {
        global $DB, $USER;
        $check = self::check($course, $ueids);
        $now = time();
        foreach ($check['eligible'] as $ueid => $row) {
            $instance = $DB->get_record('enrol', ['id' => $row->enrolid], '*', MUST_EXIST);
            $transaction = $DB->start_delegated_transaction();
            enrol_get_plugin($row->method)->update_user_enrol($instance, $row->userid, ENROL_USER_SUSPENDED);
            $DB->insert_record(course_data::TABLE, (object) [
                'courseid' => $course->id,
                'userid' => $row->userid,
                'userenrolmentid' => $ueid,
                'enrolid' => $row->enrolid,
                'enrol' => $row->method,
                'timesuspended' => $now,
                'suspendedby' => $USER->id,
                'reason' => $reason === '' ? null : $reason,
                'courseinfeed' => empty($check['courseinfeed'][$row->method]) ? 0 : 1,
            ]);
            $transaction->allow_commit();
        }
        return ['suspended' => $check['eligible'], 'skipped' => $check['skipped']];
    }
}

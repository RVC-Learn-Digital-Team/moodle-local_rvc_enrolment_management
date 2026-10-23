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

require_once(__DIR__ . '/feed_testcase.php');

/**
 * Checking and suspending a selection, and the course page's suspended lists.
 *
 * @package   local_rvc_enrolment_management
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_rvc_enrolment_management\local\suspender
 * @covers    \local_rvc_enrolment_management\local\course_data
 */
final class suspender_test extends feed_testcase {
    /** @var \stdClass Course with ID number C1. */
    protected $course;

    /** @var \stdClass[] Users keyed by short name: alice (listed), bob and carol (not listed). */
    protected $users;

    /**
     * Creates course C1 with three database enrolments; only alice is in the feed.
     */
    protected function setUp(): void {
        parent::setUp();
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course(['idnumber' => 'C1']);
        foreach (['alice', 'bob', 'carol'] as $name) {
            $this->users[$name] = $generator->create_user(['username' => "$name@example.com"]);
            $this->enrol_by_database($this->course, $this->users[$name]);
        }
        $this->feed('C1', 'alice@example.com');
        $this->setAdminUser();
    }

    /**
     * User enrolment id for a user's database enrolment in the course.
     *
     * @param string $name key into $this->users
     * @return int
     */
    protected function ueid(string $name): int {
        global $DB;
        $sql = "SELECT ue.id FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE e.courseid = ? AND e.enrol = 'database' AND ue.userid = ?";
        return (int) $DB->get_field_sql($sql, [$this->course->id, $this->users[$name]->id]);
    }

    /**
     * People not in the feed are suspended and recorded with the reason; roles are kept.
     */
    public function test_suspends_people_not_in_the_feed(): void {
        global $DB, $USER;
        $result = suspender::suspend($this->course, [$this->ueid('bob'), $this->ueid('carol')], 'Left the programme');

        $this->assertCount(2, $result['suspended']);
        $this->assertSame([], $result['skipped']);
        $this->assertEquals(ENROL_USER_SUSPENDED, $DB->get_field('user_enrolments', 'status', ['id' => $this->ueid('bob')]));
        $record = $DB->get_record(course_data::TABLE, ['userenrolmentid' => $this->ueid('bob')], '*', MUST_EXIST);
        $this->assertSame('Left the programme', $record->reason);
        $this->assertEquals($USER->id, $record->suspendedby);
        $this->assertEquals(1, $record->courseinfeed);
        $context = \context_course::instance($this->course->id);
        $this->assertNotEmpty(get_user_roles($context, $this->users['bob']->id));
    }

    /**
     * Someone the feed lists is skipped, even if they were selected.
     */
    public function test_skips_people_still_in_the_feed(): void {
        global $DB;
        $result = suspender::suspend($this->course, [$this->ueid('alice')], '');

        $this->assertSame([], $result['suspended']);
        $this->assertSame([$this->ueid('alice') => suspender::SKIP_LISTED], $result['skipped']);
        $this->assertEquals(ENROL_USER_ACTIVE, $DB->get_field('user_enrolments', 'status', ['id' => $this->ueid('alice')]));
    }

    /**
     * Someone added back to the feed between the check and the suspension is skipped.
     */
    public function test_rechecks_the_feed_at_suspension(): void {
        $check = suspender::check($this->course, [$this->ueid('bob')]);
        $this->assertArrayHasKey($this->ueid('bob'), $check['eligible']);

        $this->feed('C1', 'bob@example.com');
        $result = suspender::suspend($this->course, [$this->ueid('bob')], '');

        $this->assertSame([$this->ueid('bob') => suspender::SKIP_LISTED], $result['skipped']);
    }

    /**
     * Nothing is suspended when the feed cannot be read.
     */
    public function test_skips_everyone_when_the_feed_is_unavailable(): void {
        set_config('remoteenroltable', 'rvcem_no_such_table', 'enrol_database');
        $result = suspender::suspend($this->course, [$this->ueid('bob')], '');

        $this->assertSame([], $result['suspended']);
        $this->assertSame([$this->ueid('bob') => suspender::SKIP_FEEDUNAVAILABLE], $result['skipped']);
    }

    /**
     * Already suspended or unknown enrolments are skipped, and a reason is optional.
     */
    public function test_skips_inactive_and_unknown_enrolments(): void {
        global $DB;
        suspender::suspend($this->course, [$this->ueid('carol')], '');
        $result = suspender::suspend($this->course, [$this->ueid('carol'), 999999], '');

        $this->assertSame([], $result['suspended']);
        $this->assertSame(suspender::SKIP_NOTACTIVE, $result['skipped'][$this->ueid('carol')]);
        $this->assertSame(suspender::SKIP_NOTACTIVE, $result['skipped'][999999]);
        $this->assertNull($DB->get_field(course_data::TABLE, 'reason', ['userenrolmentid' => $this->ueid('carol')]));
    }

    /**
     * Warnings appear only above the configured share of active enrolments.
     */
    public function test_warnings_follow_the_threshold(): void {
        set_config('warnthreshold', 50, 'local_rvc_enrolment_management');
        $one = suspender::check($this->course, [$this->ueid('bob')]);
        $two = suspender::check($this->course, [$this->ueid('bob'), $this->ueid('carol')]);

        $this->assertSame([], suspender::warnings($one['counts']));
        $this->assertSame(67, suspender::warnings($two['counts'])['database']['percent']);
    }

    /**
     * Suspended enrolments split into those this tool suspended and those suspended elsewhere.
     */
    public function test_suspended_lists_split_tool_from_elsewhere(): void {
        global $DB;
        suspender::suspend($this->course, [$this->ueid('bob')], 'Left');
        $instance = $DB->get_record('enrol', ['courseid' => $this->course->id, 'enrol' => 'database']);
        $this->plugin->update_user_enrol($instance, $this->users['carol']->id, ENROL_USER_SUSPENDED);

        $split = course_data::suspended($this->course->id, 'database');

        $this->assertSame([$this->ueid('bob')], array_keys($split['tool']));
        $this->assertSame([$this->ueid('carol')], array_keys($split['elsewhere']));
        $this->assertSame('Left', $split['tool'][$this->ueid('bob')]->reason);
        $this->assertSame(['database'], course_data::methods($this->course->id));
    }
}

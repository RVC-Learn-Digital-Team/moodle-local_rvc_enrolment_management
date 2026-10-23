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
 * Re-activating and deleting enrolments this tool suspended.
 *
 * @package   local_rvc_enrolment_management
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_rvc_enrolment_management\local\tool_suspensions
 */
final class tool_suspensions_test extends feed_testcase {
    /** @var \stdClass Course with ID number C1. */
    protected $course;

    /** @var \stdClass[] Users keyed by short name. */
    protected $users;

    /**
     * Creates course C1 where bob and carol, not in the feed, are suspended by this tool.
     */
    protected function setUp(): void {
        parent::setUp();
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course(['idnumber' => 'C1']);
        foreach (['bob', 'carol'] as $name) {
            $this->users[$name] = $generator->create_user(['username' => "$name@example.com"]);
            $this->enrol_by_database($this->course, $this->users[$name]);
        }
        $this->feed('C1', 'someone.else@example.com');
        $this->setAdminUser();
        set_config('deletiondelaydays', 0, 'local_rvc_enrolment_management');
        suspender::suspend($this->course, [$this->ueid('bob'), $this->ueid('carol')], '');
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
     * Suspension record id for a user.
     *
     * @param string $name key into $this->users
     * @return int
     */
    protected function recordid(string $name): int {
        global $DB;
        return (int) $DB->get_field(course_data::TABLE, 'id', ['userid' => $this->users[$name]->id]);
    }

    /**
     * Re-activation makes the enrolment active and stamps the record; it can't be repeated.
     */
    public function test_reactivate(): void {
        global $DB, $USER;
        $result = tool_suspensions::reactivate($this->course, [$this->recordid('bob')]);

        $this->assertCount(1, $result['done']);
        $this->assertEquals(ENROL_USER_ACTIVE, $DB->get_field('user_enrolments', 'status', ['id' => $this->ueid('bob')]));
        $record = $DB->get_record(course_data::TABLE, ['id' => $this->recordid('bob')]);
        $this->assertNotEmpty($record->timereactivated);
        $this->assertEquals($USER->id, $record->reactivatedby);

        $again = tool_suspensions::reactivate($this->course, [$this->recordid('bob')]);
        $this->assertSame([$this->recordid('bob') => tool_suspensions::SKIP_NOTFOUND], $again['skipped']);
    }

    /**
     * Deletion removes the enrolment, stamps the record and flags a last enrolment.
     */
    public function test_delete(): void {
        global $DB;
        $ueid = $this->ueid('carol');
        $check = tool_suspensions::check(tool_suspensions::DELETE, $this->course, [$this->recordid('carol')]);
        $this->assertTrue($check['eligible'][$this->recordid('carol')]->lastenrolment);

        $result = tool_suspensions::delete($this->course, [$this->recordid('carol')]);

        $this->assertCount(1, $result['done']);
        $this->assertFalse($DB->record_exists('user_enrolments', ['id' => $ueid]));
        $this->assertNotEmpty($DB->get_field(course_data::TABLE, 'timedeleted', ['id' => $this->recordid('carol')]));
        $this->assertArrayNotHasKey($ueid, course_data::suspended($this->course->id, 'database')['tool']);
    }

    /**
     * Deletion waits for the configured number of days.
     */
    public function test_delete_waits_for_the_delay(): void {
        set_config('deletiondelaydays', 3, 'local_rvc_enrolment_management');
        $result = tool_suspensions::delete($this->course, [$this->recordid('bob')]);

        $this->assertSame([$this->recordid('bob') => tool_suspensions::SKIP_TOOEARLY], $result['skipped']);
    }

    /**
     * Deletion is refused when the feed lists the person again or cannot be read.
     */
    public function test_delete_rechecks_the_feed(): void {
        $this->feed('C1', 'BOB@example.com ');
        $listed = tool_suspensions::delete($this->course, [$this->recordid('bob')]);
        $this->assertSame([$this->recordid('bob') => tool_suspensions::SKIP_LISTED], $listed['skipped']);

        set_config('remoteenroltable', 'rvcem_no_such_table', 'enrol_database');
        $unavailable = tool_suspensions::delete($this->course, [$this->recordid('carol')]);
        $this->assertSame([$this->recordid('carol') => tool_suspensions::SKIP_FEEDUNAVAILABLE], $unavailable['skipped']);
    }

    /**
     * An enrolment re-activated by something else, or a record from another course, is skipped.
     */
    public function test_skips_reactivated_elsewhere_and_other_courses(): void {
        global $DB;
        $instance = $DB->get_record('enrol', ['courseid' => $this->course->id, 'enrol' => 'database']);
        $this->plugin->update_user_enrol($instance, $this->users['bob']->id, ENROL_USER_ACTIVE);
        $other = $this->getDataGenerator()->create_course(['idnumber' => 'C2']);

        $result = tool_suspensions::delete($this->course, [$this->recordid('bob')]);
        $elsewhere = tool_suspensions::reactivate($other, [$this->recordid('carol')]);

        $this->assertSame([$this->recordid('bob') => tool_suspensions::SKIP_NOTSUSPENDED], $result['skipped']);
        $this->assertSame([$this->recordid('carol') => tool_suspensions::SKIP_NOTFOUND], $elsewhere['skipped']);
    }
}

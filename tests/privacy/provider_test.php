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

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider tests.
 *
 * @package   local_rvc_enrolment_management
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_rvc_enrolment_management\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {

    /** @var \stdClass[] Courses. */
    protected $courses;

    /** @var \stdClass[] Users: two subjects and two administrators. */
    protected $users;

    /**
     * Creates two courses, two subjects, two administrators and three suspension rows.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $this->courses = [$generator->create_course(), $generator->create_course()];
        $this->users = [];
        foreach (['subject1', 'subject2', 'admin1', 'admin2'] as $name) {
            $this->users[$name] = $generator->create_user(['username' => $name]);
        }
        $this->add_suspension(0, 'subject1', 'admin1');
        $this->add_suspension(0, 'subject2', 'admin1', 'admin2');
        $this->add_suspension(1, 'subject1', 'admin2');
    }

    /**
     * Adds a suspension row.
     *
     * @param int $course index into $this->courses
     * @param string $subject key into $this->users
     * @param string $suspendedby key into $this->users
     * @param string|null $reactivatedby key into $this->users
     */
    protected function add_suspension(int $course, string $subject, string $suspendedby, ?string $reactivatedby = null): void {
        global $DB;
        $DB->insert_record(provider::TABLE, (object) [
            'courseid' => $this->courses[$course]->id,
            'userid' => $this->users[$subject]->id,
            'userenrolmentid' => 1,
            'enrolid' => 1,
            'enrol' => 'database',
            'timesuspended' => 1761100000,
            'suspendedby' => $this->users[$suspendedby]->id,
            'reason' => 'Left the programme',
            'courseinfeed' => 0,
            'timereactivated' => $reactivatedby ? 1761200000 : 0,
            'reactivatedby' => $reactivatedby ? $this->users[$reactivatedby]->id : 0,
        ]);
    }

    /**
     * Course context by index.
     *
     * @param int $course
     * @return \context_course
     */
    protected function context(int $course): \context_course {
        return \context_course::instance($this->courses[$course]->id);
    }

    /**
     * Contexts are found for subjects and for acting administrators.
     */
    public function test_contexts_cover_subjects_and_administrators(): void {
        $subject = provider::get_contexts_for_userid($this->users['subject1']->id)->get_contextids();
        $admin = provider::get_contexts_for_userid($this->users['admin1']->id)->get_contextids();

        $this->assertEqualsCanonicalizing([$this->context(0)->id, $this->context(1)->id], $subject);
        $this->assertEquals([$this->context(0)->id], $admin);
    }

    /**
     * Every subject and administrator in a course is listed.
     */
    public function test_users_in_context(): void {
        $userlist = new userlist($this->context(0), 'local_rvc_enrolment_management');
        provider::get_users_in_context($userlist);

        $expected = [];
        foreach (['subject1', 'subject2', 'admin1', 'admin2'] as $name) {
            $expected[] = $this->users[$name]->id;
        }
        $this->assertEqualsCanonicalizing($expected, $userlist->get_userids());
    }

    /**
     * A subject's rows are exported with the reason.
     */
    public function test_export_user_data(): void {
        $contextlist = new approved_contextlist($this->users['subject1'], 'local_rvc_enrolment_management',
            [$this->context(0)->id]);
        provider::export_user_data($contextlist);

        $data = writer::with_context($this->context(0))->get_data([get_string('pluginname', 'local_rvc_enrolment_management')]);
        $this->assertCount(1, $data->suspensions);
        $this->assertSame('Left the programme', $data->suspensions[0]->reason);
    }

    /**
     * Deleting a subject's data in one course leaves other courses alone.
     */
    public function test_delete_data_for_subject_keeps_other_courses(): void {
        global $DB;
        $contextlist = new approved_contextlist($this->users['subject1'], 'local_rvc_enrolment_management',
            [$this->context(0)->id]);
        provider::delete_data_for_user($contextlist);

        $this->assertFalse($DB->record_exists(provider::TABLE,
            ['courseid' => $this->courses[0]->id, 'userid' => $this->users['subject1']->id]));
        $this->assertTrue($DB->record_exists(provider::TABLE,
            ['courseid' => $this->courses[1]->id, 'userid' => $this->users['subject1']->id]));
    }

    /**
     * Deleting an administrator's data blanks them as the actor but keeps the rows.
     */
    public function test_delete_data_for_administrator_blanks_them_but_keeps_rows(): void {
        global $DB;
        $contextlist = new approved_contextlist($this->users['admin1'], 'local_rvc_enrolment_management',
            [$this->context(0)->id]);
        provider::delete_data_for_user($contextlist);

        $this->assertEquals(2, $DB->count_records(provider::TABLE, ['courseid' => $this->courses[0]->id]));
        $this->assertFalse($DB->record_exists(provider::TABLE, ['suspendedby' => $this->users['admin1']->id]));
    }

    /**
     * Deleting several users removes subjects and blanks actors in that course only.
     */
    public function test_delete_data_for_users(): void {
        global $DB;
        $userlist = new approved_userlist($this->context(0), 'local_rvc_enrolment_management',
            [$this->users['subject2']->id, $this->users['admin2']->id]);
        provider::delete_data_for_users($userlist);

        $this->assertFalse($DB->record_exists(provider::TABLE, ['userid' => $this->users['subject2']->id]));
        $this->assertFalse($DB->record_exists(provider::TABLE,
            ['courseid' => $this->courses[0]->id, 'reactivatedby' => $this->users['admin2']->id]));
        $this->assertTrue($DB->record_exists(provider::TABLE,
            ['courseid' => $this->courses[1]->id, 'suspendedby' => $this->users['admin2']->id]));
    }

    /**
     * Deleting a course context removes only that course's rows.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;
        provider::delete_data_for_all_users_in_context($this->context(0));

        $this->assertEquals(0, $DB->count_records(provider::TABLE, ['courseid' => $this->courses[0]->id]));
        $this->assertEquals(1, $DB->count_records(provider::TABLE, ['courseid' => $this->courses[1]->id]));
    }
}

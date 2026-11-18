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
 * Reading uploaded lists of people for one course.
 *
 * @package   local_rvc_enrolment_management
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_rvc_enrolment_management\local\upload
 * @covers    \local_rvc_enrolment_management\local\course_data
 */
final class upload_test extends \advanced_testcase {
    /** @var \stdClass Course with ID number C1 and short name SHORT1. */
    protected $course;

    /** @var \stdClass[] Users keyed by short name. */
    protected $users;

    /**
     * Creates a course and two people.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course(['idnumber' => 'C1', 'shortname' => 'SHORT1']);
        $this->users = [
            'alice' => $generator->create_user(['email' => 'alice@example.com']),
            'bob' => $generator->create_user(['email' => 'bob@example.com']),
        ];
    }

    /**
     * Parses CSV text with comma delimiter and UTF-8 encoding.
     *
     * @param string $csv
     * @return array
     */
    protected function parse(string $csv): array {
        return upload::parse($csv, 'UTF-8', 'comma');
    }

    /**
     * People by email match ignoring capitals, and the course by ID number.
     */
    public function test_email_and_idnumber(): void {
        $result = $this->parse("email,idnumber\nALICE@example.com,C1\nbob@example.com,C1\n");

        $this->assertNull($result['error']);
        $this->assertEquals($this->course->id, $result['course']->id);
        $this->assertEqualsCanonicalizing([$this->users['alice']->id, $this->users['bob']->id], array_keys($result['userids']));
        $this->assertSame([], $result['problems']);
    }

    /**
     * People by user ID, and the course by course ID or short name; header case doesn't matter.
     */
    public function test_userid_with_courseid_or_shortname(): void {
        $byid = $this->parse("UserID,CourseID\n{$this->users['alice']->id},{$this->course->id}\n");
        $byshortname = $this->parse("userid,shortname\n{$this->users['bob']->id},SHORT1\n");

        $this->assertSame([(int) $this->users['alice']->id], array_keys($byid['userids']));
        $this->assertEquals($this->course->id, $byshortname['course']->id);
    }

    /**
     * Unknown people and unreadable lines are reported with their line numbers.
     */
    public function test_problems_are_reported(): void {
        $result = $this->parse("email,idnumber\nnobody@example.com,C1\n,C1\nalice@example.com,C1\n");

        $this->assertSame([(int) $this->users['alice']->id], array_keys($result['userids']));
        $this->assertSame([
            ['line' => 2, 'value' => 'nobody@example.com', 'reason' => 'usernotfound'],
            ['line' => 3, 'value' => ', C1', 'reason' => 'unreadable'],
        ], $result['problems']);
    }

    /**
     * An email shared by two accounts is ambiguous.
     */
    public function test_shared_email_is_ambiguous(): void {
        $this->getDataGenerator()->create_user(['email' => 'alice@example.com']);
        $result = $this->parse("email,idnumber\nalice@example.com,C1\n");

        $this->assertSame('userambiguous', $result['problems'][0]['reason']);
    }

    /**
     * Whole files are refused for wrong columns, several courses, an unknown course or no rows.
     */
    public function test_files_refused(): void {
        $this->assertSame('upload_badcolumns', $this->parse("email,userid\nalice@example.com,1\n")['error']);
        $this->assertSame('upload_badcolumns', $this->parse("email,idnumber,shortname\na@example.com,C1,S\n")['error']);
        $twocourses = "email,idnumber\nalice@example.com,C1\nbob@example.com,C2\n";
        $this->assertSame('upload_multiplecourses', $this->parse($twocourses)['error']);
        $this->assertSame('upload_coursenotfound', $this->parse("email,idnumber\nalice@example.com,NOPE\n")['error']);
        $this->assertSame('upload_emptyfile', $this->parse("email,idnumber\n,\n")['error']);
    }

    /**
     * A stored upload is found again for its own course only.
     */
    public function test_store_and_load(): void {
        $result = $this->parse("email,idnumber\nalice@example.com,C1\n");
        $key = upload::store($result, 'list.csv');

        $this->assertSame([(int) $this->users['alice']->id], upload::load($key, $this->course->id)['userids']);
        $this->assertSame('list.csv', upload::load($key, $this->course->id)['filename']);
        $this->assertNull(upload::load($key, $this->course->id + 1));
        $this->assertNull(upload::load('nosuchkey', $this->course->id));
    }

    /**
     * People with any database-family enrolment in the course are found; others are not.
     */
    public function test_users_with_methods(): void {
        global $DB;
        $plugin = enrol_get_plugin('database');
        $instance = $DB->get_record('enrol', ['id' => $plugin->add_instance($this->course)]);
        $plugin->enrol_user($instance, $this->users['alice']->id, null, 0, 0, ENROL_USER_SUSPENDED);
        $this->getDataGenerator()->enrol_user($this->users['bob']->id, $this->course->id, 'student', 'manual');

        $this->assertSame([(int) $this->users['alice']->id], course_data::users_with_methods($this->course->id));
    }
}

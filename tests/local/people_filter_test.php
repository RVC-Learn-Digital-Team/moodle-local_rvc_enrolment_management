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
 * Filters for the course page's people lists.
 *
 * @package   local_rvc_enrolment_management
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_rvc_enrolment_management\local\people_filter
 */
final class people_filter_test extends \advanced_testcase {
    /**
     * A row as the course page lists it.
     *
     * @param array $fields overrides
     * @return \stdClass
     */
    protected function row(array $fields = []): \stdClass {
        $row = (object) ($fields + [
            'userid' => 5,
            'username' => 'jsmith',
            'email' => 'Jane.Smith@example.com',
            'usersuspended' => 0,
            'userdeleted' => 0,
        ]);
        foreach (\core_user\fields::get_name_fields() as $field) {
            $row->$field = $row->$field ?? '';
        }
        $row->firstname = $fields['firstname'] ?? 'Jane';
        $row->lastname = $fields['lastname'] ?? 'Smith';
        return $row;
    }

    /**
     * The account state comes from the deleted and suspended flags, deleted first.
     */
    public function test_account(): void {
        $this->assertSame('active', people_filter::account($this->row()));
        $this->assertSame('suspended', people_filter::account($this->row(['usersuspended' => 1])));
        $this->assertSame('deleted', people_filter::account($this->row(['usersuspended' => 1, 'userdeleted' => 1])));
    }

    /**
     * Empty filters pass everything; method, role and account must match exactly.
     */
    public function test_method_role_and_account(): void {
        $row = $this->row(['usersuspended' => 1]);
        $this->assertTrue(people_filter::matches('database', $row, 'Student', []));
        $this->assertFalse(people_filter::matches('database', $row, 'Student', ['method' => 'databaseextendedv2']));
        $this->assertFalse(people_filter::matches('database', $row, 'Student', ['role' => 'Teacher']));
        $this->assertTrue(people_filter::matches('database', $row, 'Student', ['account' => 'suspended']));
        $this->assertFalse(people_filter::matches('database', $row, 'Student', ['account' => 'active']));
    }

    /**
     * Every person word must appear in the name, email or username, ignoring capitals.
     */
    public function test_person_words(): void {
        $row = $this->row();
        $this->assertTrue(people_filter::matches('database', $row, '', ['person' => 'smith']));
        $this->assertTrue(people_filter::matches('database', $row, '', ['person' => 'JANE example.com']));
        $this->assertTrue(people_filter::matches('database', $row, '', ['person' => 'jsmi']));
        $this->assertFalse(people_filter::matches('database', $row, '', ['person' => 'jane jones']));
    }
}

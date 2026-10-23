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

use local_rvc_enrolment_management\table\courses_table;

/**
 * Course search filters and the results table's counts.
 *
 * @package   local_rvc_enrolment_management
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_rvc_enrolment_management\local\course_filter
 * @covers    \local_rvc_enrolment_management\table\courses_table
 */
final class course_filter_test extends \advanced_testcase {
    /** @var \stdClass[] Categories: undergraduate, its child bvetmed, and postgraduate. */
    protected $categories;

    /** @var \stdClass[] Courses keyed by short label. */
    protected $courses;

    /**
     * Creates three courses with database-family instances and one with only manual enrolment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $undergraduate = $generator->create_category(['name' => 'Undergraduate']);
        $this->categories = [
            'undergraduate' => $undergraduate,
            'bvetmed' => $generator->create_category(['name' => 'BVetMed', 'parent' => $undergraduate->id]),
            'postgraduate' => $generator->create_category(['name' => 'Postgraduate']),
        ];

        $this->courses = [
            'loc' => $generator->create_course(['fullname' => 'BVetMed Year 1 Locomotor', 'shortname' => '1VET-LOC',
                'idnumber' => '1VET-LOC-202526', 'category' => $this->categories['bvetmed']->id]),
            'car' => $generator->create_course(['fullname' => 'BVetMed Year 2 Cardio', 'shortname' => '2VET-CAR',
                'idnumber' => '2VET-CAR-202526', 'category' => $this->categories['bvetmed']->id]),
            'msc' => $generator->create_course(['fullname' => 'MSc One Health', 'shortname' => 'MSC-OH',
                'idnumber' => 'MSC-OH-202627', 'category' => $this->categories['postgraduate']->id]),
            'manual' => $generator->create_course(['fullname' => 'BVetMed Manual Only', 'shortname' => 'MAN',
                'idnumber' => 'MAN-202526', 'category' => $this->categories['bvetmed']->id]),
        ];
        $this->add_instance('loc', 'database');
        $this->add_instance('car', 'databaseextendedv2');
        $this->add_instance('msc', 'database');
    }

    /**
     * Adds an enrol instance row, as the extended plugin is not installed in tests.
     *
     * @param string $course key into $this->courses
     * @param string $method enrol method
     * @return \stdClass the instance
     */
    protected function add_instance(string $course, string $method): \stdClass {
        global $DB;
        $instance = (object) [
            'enrol' => $method,
            'courseid' => $this->courses[$course]->id,
            'status' => ENROL_INSTANCE_ENABLED,
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $instance->id = $DB->insert_record('enrol', $instance);
        return $instance;
    }

    /**
     * Course keys matching the filters, in course id order.
     *
     * @param array $filters
     * @return string[]
     */
    protected function matching(array $filters): array {
        global $DB;
        [$where, $params] = course_filter::sql($filters);
        $sql = "SELECT c.id FROM {course} c JOIN {course_categories} cc ON cc.id = c.category WHERE $where ORDER BY c.id";
        $ids = $DB->get_fieldset_sql($sql, $params);
        $keys = [];
        foreach ($this->courses as $key => $course) {
            if (in_array($course->id, $ids)) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /**
     * With no filters, only courses with a database-family instance are listed.
     */
    public function test_no_filters_lists_database_family_courses_only(): void {
        $this->assertSame(['loc', 'car', 'msc'], $this->matching([]));
    }

    /**
     * The method filter limits courses to one enrolment method.
     */
    public function test_method_filter(): void {
        $this->assertSame(['loc', 'msc'], $this->matching(['method' => 'database']));
        $this->assertSame(['car'], $this->matching(['method' => 'databaseextendedv2']));
    }

    /**
     * Course IDs match any of the numbers given, ignoring other text.
     */
    public function test_course_ids_match_any_number(): void {
        $ids = $this->courses['loc']->id . ', ' . $this->courses['msc']->id;
        $this->assertSame(['loc', 'msc'], $this->matching(['courseids' => $ids]));
        $this->assertSame(['car'], $this->matching(['courseids' => 'id ' . $this->courses['car']->id]));
    }

    /**
     * Every course name word must appear in the full name or the short name.
     */
    public function test_course_name_words_match_full_or_short_name(): void {
        $this->assertSame(['loc', 'car'], $this->matching(['coursename' => 'bvetmed']));
        $this->assertSame(['loc'], $this->matching(['coursename' => 'BVETMED 1vet']));
        $this->assertSame([], $this->matching(['coursename' => 'bvetmed health']));
    }

    /**
     * Every ID number word must appear in the ID number.
     */
    public function test_idnumber_words_all_match(): void {
        $this->assertSame(['loc'], $this->matching(['idnumber' => '1vet 2025']));
        $this->assertSame(['loc', 'car'], $this->matching(['idnumber' => '202526']));
        $this->assertSame(['msc'], $this->matching(['idnumber' => '2026']));
        $this->assertSame([], $this->matching(['idnumber' => 'vet 202627']));
    }

    /**
     * A category includes its subcategories.
     */
    public function test_category_includes_subcategories(): void {
        $this->assertSame(['loc', 'car'], $this->matching(['category' => $this->categories['undergraduate']->id]));
        $this->assertSame(['msc'], $this->matching(['category' => $this->categories['postgraduate']->id]));
    }

    /**
     * Wildcard characters typed into a filter are matched literally.
     */
    public function test_wildcards_are_literal(): void {
        $this->assertSame([], $this->matching(['coursename' => '%']));
        $this->assertSame([], $this->matching(['idnumber' => '_']));
    }

    /**
     * The results table counts active and suspended enrolments for each method.
     */
    public function test_table_counts_active_and_suspended(): void {
        global $DB;
        $plugin = enrol_get_plugin('database');
        $instance = $DB->get_record('enrol', ['courseid' => $this->courses['loc']->id, 'enrol' => 'database']);
        $generator = $this->getDataGenerator();
        $plugin->enrol_user($instance, $generator->create_user()->id, null, 0, 0, ENROL_USER_ACTIVE);
        $plugin->enrol_user($instance, $generator->create_user()->id, null, 0, 0, ENROL_USER_SUSPENDED);

        $baseurl = new \moodle_url('/local/rvc_enrolment_management/index.php');
        $table = new courses_table(['courseids' => (string) $this->courses['loc']->id], $baseurl);
        $table->setup();
        $table->query_db(50, false);
        $rows = array_values($table->rawdata);

        $this->assertCount(1, $rows);
        $this->assertEquals(1, $rows[0]->dbactive);
        $this->assertEquals(1, $rows[0]->dbsuspended);
        $this->assertEmpty($rows[0]->extinstances);
    }
}

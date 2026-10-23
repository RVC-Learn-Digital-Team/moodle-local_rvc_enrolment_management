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
 * Feed reading and course review, run through core enrol_database's own connection code.
 *
 * Like core's enrol_database tests, these point the plugin at a feed table in the test database,
 * which needs transactions off so the separate feed connection can see the rows.
 *
 * @package   local_rvc_enrolment_management
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_rvc_enrolment_management\local\feed_reader
 * @covers    \local_rvc_enrolment_management\local\feed_result
 * @covers    \local_rvc_enrolment_management\local\course_review
 */
final class course_review_test extends \advanced_testcase {

    /** @var string Feed table created in the test database. */
    const FEEDTABLE = 'local_rvcem_test_feed';

    /** @var string|false error_log setting to restore. */
    protected $oldlog;

    /** @var \enrol_plugin Core database enrol plugin. */
    protected $plugin;

    /**
     * Points core enrol_database at a feed table in the test database.
     */
    protected function setUp(): void {
        global $CFG, $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();

        // Discard ADOdb error logging, as core's enrol_database tests do.
        $this->oldlog = ini_get('error_log');
        ini_set('error_log', "$CFG->dataroot/testlog.log");

        set_config('dbencoding', 'utf-8', 'enrol_database');
        set_config('dbhost', $CFG->dbhost, 'enrol_database');
        set_config('dbuser', $CFG->dbuser, 'enrol_database');
        set_config('dbpass', $CFG->dbpass, 'enrol_database');
        set_config('dbname', $CFG->dbname, 'enrol_database');
        if (!empty($CFG->dboptions['dbport'])) {
            set_config('dbhost', $CFG->dbhost . ':' . $CFG->dboptions['dbport'], 'enrol_database');
        }
        switch ($DB->get_dbfamily()) {
            case 'mysql':
                set_config('dbtype', 'mysqli', 'enrol_database');
                set_config('dbsetupsql', "SET NAMES 'UTF-8'", 'enrol_database');
                set_config('dbsybasequoting', '0', 'enrol_database');
                break;
            case 'postgres':
                set_config('dbtype', 'postgres7', 'enrol_database');
                set_config('dbsetupsql', "SET NAMES 'UTF-8'", 'enrol_database');
                set_config('dbsybasequoting', '0', 'enrol_database');
                break;
            default:
                $this->markTestSkipped('Feed tests run on MySQL and PostgreSQL only.');
        }

        $dbman = $DB->get_manager();
        $table = new \xmldb_table(self::FEEDTABLE);
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_CHAR, '255', null, null, null);
        $table->add_field('userid', XMLDB_TYPE_CHAR, '255', null, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }
        $dbman->create_table($table);

        set_config('remoteenroltable', $CFG->prefix . self::FEEDTABLE, 'enrol_database');
        set_config('remotecoursefield', 'courseid', 'enrol_database');
        set_config('remoteuserfield', 'userid', 'enrol_database');
        set_config('localcoursefield', 'idnumber', 'enrol_database');
        set_config('localuserfield', 'username', 'enrol_database');
        set_config('debugdb', '0', 'enrol_database');

        $this->plugin = enrol_get_plugin('database');
    }

    /**
     * Drops the feed table and restores error logging.
     */
    protected function tearDown(): void {
        global $DB;
        $dbman = $DB->get_manager();
        $table = new \xmldb_table(self::FEEDTABLE);
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }
        ini_set('error_log', $this->oldlog);
        parent::tearDown();
    }

    /**
     * Adds a feed row.
     *
     * @param string $courseid
     * @param string $userid
     */
    protected function feed(string $courseid, string $userid): void {
        global $DB;
        $DB->insert_record(self::FEEDTABLE, (object) ['courseid' => $courseid, 'userid' => $userid]);
    }

    /**
     * Enrols a user through core database enrolment.
     *
     * @param \stdClass $course
     * @param \stdClass $user
     * @param int $status ENROL_USER_ACTIVE or ENROL_USER_SUSPENDED
     */
    protected function enrol_by_database(\stdClass $course, \stdClass $user, int $status = ENROL_USER_ACTIVE): void {
        global $DB;
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'database']);
        if (!$instance) {
            $instance = $DB->get_record('enrol', ['id' => $this->plugin->add_instance($course)]);
        }
        $studentrole = $DB->get_field('role', 'id', ['shortname' => 'student']);
        $this->plugin->enrol_user($instance, $user->id, $studentrole, 0, 0, $status);
    }

    /**
     * Usernames of a set of enrolment rows, sorted.
     *
     * @param \stdClass[] $enrolments
     * @return string[]
     */
    protected function usernames(array $enrolments): array {
        $names = array_map(fn($enrolment) => $enrolment->username, array_values($enrolments));
        sort($names);
        return $names;
    }

    /**
     * The feed lists people for one course only, ignoring capitals and surrounding spaces.
     */
    public function test_feed_lists_people_ignoring_capitals_and_spaces(): void {
        $this->feed('C1', ' Alice@Example.com ');
        $this->feed('C1', 'bob@example.com');
        $this->feed('C2', 'carol@example.com');

        $result = (new feed_reader('database'))->read_course('C1');

        $this->assertTrue($result->available);
        $this->assertSame(2, $result->rows);
        $this->assertTrue($result->course_in_feed());
        $this->assertTrue($result->lists('alice@example.com'));
        $this->assertTrue($result->lists('BOB@example.com'));
        $this->assertFalse($result->lists('carol@example.com'));
    }

    /**
     * Active enrolments split by listing; suspended enrolments are left out and account suspension is reported.
     */
    public function test_review_splits_listed_from_not_listed(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['idnumber' => 'C1']);
        $alice = $generator->create_user(['username' => 'alice@example.com']);
        $bob = $generator->create_user(['username' => 'bob@example.com']);
        $carol = $generator->create_user(['username' => 'carol@example.com', 'suspended' => 1]);
        $dave = $generator->create_user(['username' => 'dave@example.com']);
        $this->enrol_by_database($course, $alice);
        $this->enrol_by_database($course, $bob);
        $this->enrol_by_database($course, $carol);
        $this->enrol_by_database($course, $dave, ENROL_USER_SUSPENDED);
        $this->feed('C1', 'ALICE@example.com');

        $review = course_review::review($course, 'database');

        $this->assertTrue($review->feed->available);
        $this->assertSame(['alice@example.com'], $this->usernames($review->listed));
        $this->assertSame(['bob@example.com', 'carol@example.com'], $this->usernames($review->notlisted));
        $this->assertCount(3, $review->active);
        $carolrow = array_values(array_filter($review->notlisted, fn($row) => $row->userid == $carol->id))[0];
        $this->assertEquals(1, $carolrow->usersuspended);
    }

    /**
     * A course absent from the feed is normal: everyone in it is not listed.
     */
    public function test_course_missing_from_feed_lists_nobody(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['idnumber' => 'OLD-2024']);
        $alice = $generator->create_user(['username' => 'alice@example.com']);
        $this->enrol_by_database($course, $alice);
        $this->feed('C1', 'alice@example.com');

        $review = course_review::review($course, 'database');

        $this->assertTrue($review->feed->available);
        $this->assertFalse($review->feed->course_in_feed());
        $this->assertSame(['alice@example.com'], $this->usernames($review->notlisted));
    }

    /**
     * A course with no idnumber is never matched to blank feed rows.
     */
    public function test_empty_idnumber_only_checks_the_connection(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['idnumber' => '']);
        $alice = $generator->create_user(['username' => 'alice@example.com']);
        $this->enrol_by_database($course, $alice);
        // A blank feed row must not be read as listing anyone for a course with no idnumber.
        $this->feed('', 'alice@example.com');

        $review = course_review::review($course, 'database');

        $this->assertTrue($review->feed->available);
        $this->assertSame(0, $review->feed->rows);
        $this->assertSame(['alice@example.com'], $this->usernames($review->notlisted));
    }

    /**
     * An idnumber with stray spaces is looked up again trimmed.
     */
    public function test_untrimmed_idnumber_is_retried_trimmed(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['idnumber' => 'C1']);
        $DB->set_field('course', 'idnumber', ' C1', ['id' => $course->id]);
        $course = get_course($course->id);
        $alice = $generator->create_user(['username' => 'alice@example.com']);
        $this->enrol_by_database($course, $alice);
        $this->feed('C1', 'alice@example.com');

        $review = course_review::review($course, 'database');

        $this->assertSame(1, $review->feed->rows);
        $this->assertSame(['alice@example.com'], $this->usernames($review->listed));
    }

    /**
     * A failed connection makes the feed unavailable and prints nothing, even with debugdb on.
     */
    public function test_connection_failure_makes_feed_unavailable_without_output(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['idnumber' => 'C1']);
        $alice = $generator->create_user(['username' => 'alice@example.com']);
        $this->enrol_by_database($course, $alice);
        set_config('dbname', 'rvcem_no_such_database', 'enrol_database');
        set_config('debugdb', '1', 'enrol_database');
        $level = ob_get_level();

        $review = course_review::review($course, 'database');

        $this->expectOutputString('');
        $this->assertSame($level, ob_get_level());
        $this->assertFalse($review->feed->available);
        $this->assertCount(1, $review->active);
        $this->assertSame([], $review->notlisted);
        $this->assertSame([], $review->listed);
    }

    /**
     * A query failure makes the feed unavailable.
     */
    public function test_missing_feed_table_makes_feed_unavailable(): void {
        set_config('remoteenroltable', 'rvcem_no_such_table', 'enrol_database');

        $this->assertFalse((new feed_reader('database'))->read_course('C1')->available);
    }

    /**
     * An enrol plugin without db_init() or db_get_sql() makes the feed unavailable.
     */
    public function test_plugin_without_connection_code_makes_feed_unavailable(): void {
        $reader = new class('database') extends feed_reader {
            protected function call(string $name, ...$args) {
                throw new \ReflectionException('Method does not exist');
            }
        };

        $this->assertFalse($reader->read_course('C1')->available);
    }

    /**
     * A local match field outside the enrol plugin's options makes the feed unavailable.
     */
    public function test_unusable_match_setting_makes_feed_unavailable(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['idnumber' => 'C1']);
        set_config('localuserfield', 'firstname', 'enrol_database');

        $this->assertFalse(course_review::review($course, 'database')->feed->available);
    }

    /**
     * Other active enrolments in the course are reported with each row.
     */
    public function test_other_active_enrolments_are_reported(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['idnumber' => 'C1']);
        $bob = $generator->create_user(['username' => 'bob@example.com']);
        $this->enrol_by_database($course, $bob);
        $generator->enrol_user($bob->id, $course->id, 'student', 'manual');

        $review = course_review::review($course, 'database');

        $row = reset($review->notlisted);
        $this->assertSame(['manual'], $row->otheractive);
    }

    /**
     * The feed is named by transport and database name, never by connection details.
     */
    public function test_describe_names_transport_without_credentials(): void {
        set_config('dbtype', 'mysqli://feeduser:secretpass@feedhost/feeddb', 'enrol_database');
        set_config('dbname', '', 'enrol_database');
        $this->assertSame('MySQL', (new feed_reader('database'))->describe());

        set_config('dbtype', 'odbc_mssql', 'enrol_database');
        set_config('dbname', 'Moodle_Snapshot', 'enrol_database');
        $this->assertSame('ODBC (SQL Server) · Moodle_Snapshot', (new feed_reader('database'))->describe());
    }
}

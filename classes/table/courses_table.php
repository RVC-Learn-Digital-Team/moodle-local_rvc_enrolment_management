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

namespace local_rvc_enrolment_management\table;

use local_rvc_enrolment_management\local\course_filter;

/**
 * Courses matching the search filters, with active and suspended counts for each method.
 *
 * No feed is queried here: the counts come from Moodle's own enrolment records.
 *
 * @package   local_rvc_enrolment_management
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class courses_table extends \core_table\sql_table {
    /** @var string[] Category paths by category id. */
    protected $categories;

    /**
     * Constructor.
     *
     * @param array $filters see course_filter::sql()
     * @param \moodle_url $baseurl page URL carrying the filters
     */
    public function __construct(array $filters, \moodle_url $baseurl) {
        parent::__construct('local_rvc_enrolment_management_courses');
        $component = 'local_rvc_enrolment_management';
        $this->categories = \core_course_category::make_categories_list();

        $this->define_columns(['id', 'idnumber', 'shortname', 'fullname', 'category', 'visible', 'database', 'extended']);
        $this->define_headers([
            get_string('courseid', $component),
            get_string('idnumber'),
            get_string('shortname'),
            get_string('fullname'),
            get_string('category'),
            get_string('visible'),
            get_string('method_database', $component),
            get_string('method_databaseextendedv2', $component),
        ]);
        $this->define_baseurl($baseurl);
        $this->sortable(true, 'fullname', SORT_ASC);
        foreach (['category', 'database', 'extended'] as $column) {
            $this->no_sorting($column);
        }

        [$where, $params] = course_filter::sql($filters);
        $active = ENROL_USER_ACTIVE;
        $counts = "SELECT e.courseid,
                          SUM(CASE WHEN e.enrol = 'database' THEN 1 ELSE 0 END) AS dbinstances,
                          SUM(CASE WHEN e.enrol = 'database' AND ue.status = $active THEN 1 ELSE 0 END) AS dbactive,
                          SUM(CASE WHEN e.enrol = 'database' AND ue.status <> $active THEN 1 ELSE 0 END) AS dbsuspended,
                          SUM(CASE WHEN e.enrol = 'databaseextendedv2' THEN 1 ELSE 0 END) AS extinstances,
                          SUM(CASE WHEN e.enrol = 'databaseextendedv2' AND ue.status = $active THEN 1 ELSE 0 END) AS extactive,
                          SUM(CASE WHEN e.enrol = 'databaseextendedv2' AND ue.status <> $active THEN 1 ELSE 0 END) AS extsuspended
                     FROM {enrol} e
                LEFT JOIN {user_enrolments} ue ON ue.enrolid = e.id
                    WHERE e.enrol IN ('database', 'databaseextendedv2')
                 GROUP BY e.courseid";
        $this->set_sql(
            'c.id, c.idnumber, c.shortname, c.fullname, c.category, c.visible,
             n.dbinstances, n.dbactive, n.dbsuspended, n.extinstances, n.extactive, n.extsuspended',
            "{course} c
             JOIN {course_categories} cc ON cc.id = c.category
             LEFT JOIN ($counts) n ON n.courseid = c.id",
            $where,
            $params
        );
    }

    /**
     * Full name, linking to the course's suspension page.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_fullname($row) {
        $url = new \moodle_url('/local/rvc_enrolment_management/course.php', ['id' => $row->id]);
        return \html_writer::link($url, format_string($row->fullname));
    }

    /**
     * Short name.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_shortname($row) {
        return format_string($row->shortname);
    }

    /**
     * Category path.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_category($row) {
        return s($this->categories[$row->category] ?? '');
    }

    /**
     * Course visibility.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_visible($row) {
        return $row->visible ? get_string('yes') : get_string('no');
    }

    /**
     * Core database enrolment counts.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_database($row) {
        return $this->counts($row->dbinstances, $row->dbactive, $row->dbsuspended);
    }

    /**
     * Extended database enrolment counts.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_extended($row) {
        return $this->counts($row->extinstances, $row->extactive, $row->extsuspended);
    }

    /**
     * Formats active and suspended counts, or a dash when the course has no such instance.
     *
     * @param int|null $instances rows for that method (instances times enrolments)
     * @param int|null $active
     * @param int|null $suspended
     * @return string
     */
    protected function counts($instances, $active, $suspended): string {
        if (empty($instances)) {
            return '-';
        }
        return get_string(
            'activesuspended',
            'local_rvc_enrolment_management',
            ['active' => (int) $active, 'suspended' => (int) $suspended]
        );
    }
}

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
    /** @var string[] Columns that sort by suspended count, most suspended first. */
    const SUSPENDEDCOLUMNS = ['dbsuspended', 'extsuspended'];

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

        $this->define_columns([
            'id',
            'idnumber',
            'shortname',
            'coursefullname',
            'categorysortorder',
            'visible',
            'dbsuspended',
            'extsuspended',
        ]);
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
        $this->sortable(true, 'coursefullname', SORT_ASC);

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
            'c.id, c.idnumber, c.shortname, c.fullname AS coursefullname, c.category, cc.sortorder AS categorysortorder, c.visible,
             n.dbinstances, n.dbactive, COALESCE(n.dbsuspended, 0) AS dbsuspended,
             n.extinstances, n.extactive, COALESCE(n.extsuspended, 0) AS extsuspended',
            "{course} c
             JOIN {course_categories} cc ON cc.id = c.category
             LEFT JOIN ($counts) n ON n.courseid = c.id",
            $where,
            $params
        );
    }

    /**
     * Course full name, linking to the course page. The column is not called "fullname"
     * because Moodle tables treat that name as a person's first and last name.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_coursefullname($row) {
        $url = new \moodle_url('/local/rvc_enrolment_management/course.php', ['id' => $row->id]);
        return \html_writer::link($url, format_string($row->coursefullname));
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
     * Category path. Sorting this column follows Moodle's own category order.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_categorysortorder($row) {
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
    public function col_dbsuspended($row) {
        return $this->counts($row->dbinstances, $row->dbactive, $row->dbsuspended);
    }

    /**
     * Extended database enrolment counts.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_extsuspended($row) {
        return $this->counts($row->extinstances, $row->extactive, $row->extsuspended);
    }

    /**
     * Sort link for a header. The suspended-count columns sort most suspended first when chosen,
     * and the other way round when clicked again.
     *
     * @param string $text header text
     * @param string $column column name
     * @param bool $isprimary whether this column is the current primary sort column
     * @param int $order SORT_ASC or SORT_DESC
     * @return string HTML fragment
     */
    protected function sort_link($text, $column, $isprimary, $order) {
        if ($isprimary || !in_array($column, self::SUSPENDEDCOLUMNS, true)) {
            return parent::sort_link($text, $column, $isprimary, $order);
        }
        $params = [$this->request[TABLE_VAR_SORT] => $column, $this->request[TABLE_VAR_DIR] => SORT_DESC];
        return \html_writer::link(
            $this->baseurl->out(false, $params),
            $text . get_accesshide(get_string('sortby') . ' ' . $text),
            ['data-sortable' => 1, 'data-sortby' => $column, 'data-sortorder' => SORT_DESC, 'role' => 'button']
        );
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

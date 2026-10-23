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
 * Turns the course search filters into SQL for {course} c joined to {course_categories} cc.
 *
 * Every filled-in filter must match. Text filters are split on spaces and every word must
 * appear, ignoring capitals. Only courses with a database or extended database enrolment
 * instance are ever returned.
 *
 * @package   local_rvc_enrolment_management
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_filter {
    /** @var string[] Filter names, as used in page parameters. */
    const NAMES = ['courseids', 'coursename', 'idnumber', 'category', 'method'];

    /**
     * Builds the WHERE clause and parameters.
     *
     * @param array $filters courseids, coursename, idnumber (strings), category (int), method (string)
     * @return array [string $where, array $params]
     */
    public static function sql(array $filters): array {
        global $DB;

        $where = ['c.id <> :siteid'];
        $params = ['siteid' => SITEID];

        $method = (string) ($filters['method'] ?? '');
        $methods = in_array($method, feed_reader::METHODS, true) ? [$method] : feed_reader::METHODS;
        [$insql, $inparams] = $DB->get_in_or_equal($methods, SQL_PARAMS_NAMED, 'method');
        $where[] = "EXISTS (SELECT 1 FROM {enrol} fe WHERE fe.courseid = c.id AND fe.enrol $insql)";
        $params += $inparams;

        preg_match_all('/\d+/', (string) ($filters['courseids'] ?? ''), $matches);
        if ($matches[0]) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_map('intval', $matches[0]), SQL_PARAMS_NAMED, 'courseid');
            $where[] = "c.id $insql";
            $params += $inparams;
        }

        foreach (self::words($filters['coursename'] ?? '') as $i => $word) {
            $full = $DB->sql_like('c.fullname', ":fullname$i", false);
            $short = $DB->sql_like('c.shortname', ":shortname$i", false);
            $where[] = "($full OR $short)";
            $params["fullname$i"] = $params["shortname$i"] = '%' . $DB->sql_like_escape($word) . '%';
        }

        foreach (self::words($filters['idnumber'] ?? '') as $i => $word) {
            $where[] = $DB->sql_like('c.idnumber', ":idnumber$i", false);
            $params["idnumber$i"] = '%' . $DB->sql_like_escape($word) . '%';
        }

        $categoryid = (int) ($filters['category'] ?? 0);
        if ($categoryid && $category = \core_course_category::get($categoryid, IGNORE_MISSING, true)) {
            $where[] = '(cc.id = :categoryid OR ' . $DB->sql_like('cc.path', ':categorypath') . ')';
            $params['categoryid'] = $categoryid;
            $params['categorypath'] = $DB->sql_like_escape($category->path) . '/%';
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * Splits a filter value into words.
     *
     * @param mixed $value
     * @return string[]
     */
    protected static function words($value): array {
        return preg_split('/\s+/', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY);
    }
}

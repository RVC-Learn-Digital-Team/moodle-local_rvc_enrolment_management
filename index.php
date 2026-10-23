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

/**
 * Suspensions: find a course. No feed is queried on this page.
 *
 * @package   local_rvc_enrolment_management
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_rvc_enrolment_management\form\search_form;
use local_rvc_enrolment_management\local\course_filter;
use local_rvc_enrolment_management\table\courses_table;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$filters = [
    'courseids' => optional_param('courseids', '', PARAM_TEXT),
    'coursename' => optional_param('coursename', '', PARAM_TEXT),
    'idnumber' => optional_param('idnumber', '', PARAM_TEXT),
    'category' => optional_param('category', 0, PARAM_INT),
    'method' => optional_param('method', '', PARAM_ALPHANUMEXT),
];

admin_externalpage_setup('local_rvc_enrolment_management_manage', '', array_filter($filters));
require_admin();

// The filters live in the page address, so the browser's Back button keeps them.
$baseurl = new moodle_url('/local/rvc_enrolment_management/index.php');
$form = new search_form($baseurl);
$form->set_data($filters);
if ($form->is_cancelled()) {
    redirect($baseurl);
}
if ($data = $form->get_data()) {
    $params = [];
    foreach (course_filter::NAMES as $name) {
        if (!empty($data->$name)) {
            $params[$name] = $data->$name;
        }
    }
    redirect(new moodle_url($baseurl, $params));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('manageenrolments', 'local_rvc_enrolment_management'));
$form->display();
$table = new courses_table($filters, $PAGE->url);
$table->out(50, false);
echo $OUTPUT->footer();

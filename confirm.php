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
 * Confirm and carry out a suspension. The feeds are checked again on arrival and on confirm.
 *
 * @package   local_rvc_enrolment_management
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_rvc_enrolment_management\form\confirm_form;
use local_rvc_enrolment_management\local\suspender;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$courseid = required_param('courseid', PARAM_INT);
admin_externalpage_setup(
    'local_rvc_enrolment_management_manage',
    '',
    ['courseid' => $courseid],
    new moodle_url('/local/rvc_enrolment_management/confirm.php')
);
require_admin();
require_sesskey();

$component = 'local_rvc_enrolment_management';
$course = get_course($courseid);
$courseurl = new moodle_url('/local/rvc_enrolment_management/course.php', ['id' => $course->id]);
$methodname = fn(string $method) => get_string('method_' . $method, $component);

// The selection arrives from the course page as ueids[], or back from this form as uelist.
$ueids = optional_param_array('ueids', [], PARAM_INT);
if (!$ueids && ($list = optional_param('uelist', '', PARAM_SEQUENCE)) !== '') {
    $ueids = explode(',', $list);
}
if (!$ueids) {
    redirect($courseurl, get_string('selectsomeone', $component), null, \core\output\notification::NOTIFY_WARNING);
}

$check = suspender::check($course, $ueids);
$warnings = [];
foreach (suspender::warnings($check['counts']) as $method => $count) {
    $warnings[] = get_string('batchwarning', $component, $count + ['method' => $methodname($method)]);
}
$actionurl = new moodle_url('/local/rvc_enrolment_management/confirm.php', ['courseid' => $course->id]);
$form = new confirm_form($actionurl, ['ueids' => array_keys($check['eligible']), 'warnings' => $warnings]);

if ($form->is_cancelled()) {
    redirect($courseurl);
}

$skipnames = function (array $skipped) use ($DB, $component): array {
    $lines = [];
    foreach ($skipped as $ueid => $reason) {
        $user = $DB->get_record_sql(
            'SELECT u.* FROM {user} u JOIN {user_enrolments} ue ON ue.userid = u.id WHERE ue.id = ?',
            [$ueid]
        );
        $who = $user ? fullname($user) : get_string('unknownenrolment', $component, $ueid);
        $lines[] = get_string('skipped_' . $reason, $component, $who);
    }
    return $lines;
};

if ($data = $form->get_data()) {
    $result = suspender::suspend($course, explode(',', $data->uelist), trim((string) $data->reason));
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('suspensionresults', $component));
    echo $OUTPUT->notification(get_string('suspendeddone', $component, count($result['suspended'])), 'success', false);
    if ($result['suspended']) {
        $names = array_map(fn($row) => fullname($row) . ' (' . $methodname($row->method) . ')', $result['suspended']);
        echo html_writer::alist(array_map('s', $names));
    }
    if ($result['skipped']) {
        echo $OUTPUT->heading(get_string('skippedheading', $component, count($result['skipped'])), 4);
        echo html_writer::alist(array_map('s', $skipnames($result['skipped'])));
    }
    echo $OUTPUT->continue_button($courseurl);
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('confirmsuspension', $component));
echo html_writer::tag('p', s(format_string($course->fullname)));
if ($check['eligible']) {
    $names = array_map(fn($row) => fullname($row) . ' (' . $methodname($row->method) . ')', $check['eligible']);
    echo html_writer::alist(array_map('s', $names));
}
if ($check['skipped']) {
    echo $OUTPUT->heading(get_string('skippedheading', $component, count($check['skipped'])), 4);
    echo html_writer::alist(array_map('s', $skipnames($check['skipped'])));
}
if ($check['eligible']) {
    $form->display();
} else {
    echo $OUTPUT->notification(get_string('nothingtosuspend', $component), 'info', false);
    echo $OUTPUT->continue_button($courseurl);
}
echo $OUTPUT->footer();

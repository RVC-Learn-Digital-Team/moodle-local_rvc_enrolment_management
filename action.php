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
 * Confirm and carry out re-activation or deletion of enrolments this tool suspended.
 *
 * @package   local_rvc_enrolment_management
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_rvc_enrolment_management\form\tool_action_form;
use local_rvc_enrolment_management\local\tool_suspensions;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$courseid = required_param('courseid', PARAM_INT);
admin_externalpage_setup(
    'local_rvc_enrolment_management_manage',
    '',
    ['courseid' => $courseid],
    new moodle_url('/local/rvc_enrolment_management/action.php')
);
require_admin();
require_sesskey();

$component = 'local_rvc_enrolment_management';
$course = get_course($courseid);
$courseurl = new moodle_url('/local/rvc_enrolment_management/course.php', ['id' => $course->id]);
$action = required_param('action', PARAM_ALPHA);
if (!in_array($action, [tool_suspensions::REACTIVATE, tool_suspensions::DELETE], true)) {
    throw new moodle_exception('invalidparameter', 'debug');
}
$methodname = fn(string $method) => get_string('method_' . $method, $component);

// The selection arrives from the course page as suspensionids[], or back from this form as idlist.
$ids = optional_param_array('suspensionids', [], PARAM_INT);
if (!$ids && ($list = optional_param('idlist', '', PARAM_SEQUENCE)) !== '') {
    $ids = explode(',', $list);
}
if (!$ids) {
    redirect($courseurl, get_string('selectsomeone', $component), null, \core\output\notification::NOTIFY_WARNING);
}

$check = tool_suspensions::check($action, $course, $ids);
$form = new tool_action_form(
    new moodle_url('/local/rvc_enrolment_management/action.php', ['courseid' => $course->id]),
    [
        'action' => $action,
        'ids' => array_keys($check['eligible']),
        'label' => get_string($action . 'count', $component, count($check['eligible'])),
    ]
);
if ($form->is_cancelled()) {
    redirect($courseurl);
}

$names = function (array $rows) use ($methodname, $component, $action): array {
    $lines = [];
    foreach ($rows as $row) {
        $line = fullname($row) . ' (' . $methodname($row->enrol) . ')';
        if ($action === tool_suspensions::DELETE && !empty($row->lastenrolment)) {
            $line .= ' - ' . get_string('lastenrolment', $component);
        }
        $lines[] = s($line);
    }
    return $lines;
};
$skipnames = function (array $skipped) use ($DB, $component): array {
    $lines = [];
    foreach ($skipped as $id => $reason) {
        $sql = 'SELECT u.* FROM {user} u JOIN {local_rvc_enrolment_management_suspension} s ON s.userid = u.id WHERE s.id = ?';
        $user = $DB->get_record_sql($sql, [$id]);
        $who = $user ? fullname($user) : get_string('unknownenrolment', $component, $id);
        $lines[] = s(get_string('toolskipped_' . $reason, $component, $who));
    }
    return $lines;
};

if ($data = $form->get_data()) {
    $result = $action === tool_suspensions::DELETE
        ? tool_suspensions::delete($course, explode(',', $data->idlist))
        : tool_suspensions::reactivate($course, explode(',', $data->idlist));
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string($action . 'results', $component));
    echo $OUTPUT->notification(get_string($action . 'done', $component, count($result['done'])), 'success', false);
    if ($result['done']) {
        echo html_writer::alist($names($result['done']));
    }
    if ($result['skipped']) {
        echo $OUTPUT->heading(get_string('notchangedheading', $component, count($result['skipped'])), 4);
        echo html_writer::alist($skipnames($result['skipped']));
    }
    echo $OUTPUT->continue_button($courseurl);
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('confirm' . $action, $component));
echo html_writer::tag('p', s(format_string($course->fullname)));
if ($check['eligible']) {
    echo html_writer::alist($names($check['eligible']));
    if ($action === tool_suspensions::DELETE) {
        echo $OUTPUT->notification(get_string('deleteconsequences', $component), 'warning', false);
    } else if (in_array('databaseextendedv2', array_column($check['eligible'], 'enrol'), true)) {
        echo $OUTPUT->notification(get_string('reactivateextendednote', $component), 'info', false);
    }
}
if ($check['skipped']) {
    echo $OUTPUT->heading(get_string('notchangedheading', $component, count($check['skipped'])), 4);
    echo html_writer::alist($skipnames($check['skipped']));
}
if ($check['eligible']) {
    $form->display();
} else {
    echo $OUTPUT->notification(get_string('nothingtochange', $component), 'info', false);
    echo $OUTPUT->continue_button($courseurl);
}
echo $OUTPUT->footer();

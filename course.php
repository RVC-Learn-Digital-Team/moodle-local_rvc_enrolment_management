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
 * Manage enrolments for one course: feed checks, who can be suspended, and suspensions so far.
 *
 * @package   local_rvc_enrolment_management
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_rvc_enrolment_management\local\course_data;
use local_rvc_enrolment_management\local\course_review;
use local_rvc_enrolment_management\local\feed_reader;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$id = required_param('id', PARAM_INT);
$methodfilter = optional_param('method', '', PARAM_ALPHANUMEXT);
$rolefilter = optional_param('role', '', PARAM_TEXT);

$params = array_filter(['id' => $id, 'method' => $methodfilter, 'role' => $rolefilter]);
admin_externalpage_setup(
    'local_rvc_enrolment_management_manage',
    '',
    $params,
    new moodle_url('/local/rvc_enrolment_management/course.php')
);
require_admin();

$component = 'local_rvc_enrolment_management';
$course = get_course($id);
$context = context_course::instance($course->id);
$categories = core_course_category::make_categories_list();
$methods = course_data::methods($course->id);
$roles = course_data::roles($context);
$lastaccess = course_data::last_access($course->id);
$methodname = fn(string $method) => in_array($method, feed_reader::METHODS, true)
    ? get_string('method_' . $method, $component)
    : get_string('pluginname', 'enrol_' . $method);
$date = fn(int $time) => $time ? userdate($time, get_string('strftimedatetimeshort', 'langconfig')) : get_string('never');

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($course->fullname));
echo html_writer::link(new moodle_url('/local/rvc_enrolment_management/index.php'), get_string('backtosearch', $component));

// 1. Course details.
$details = new html_table();
$details->attributes['class'] = 'generaltable';
$details->data = [
    [get_string('courseid', $component), $course->id],
    [get_string('idnumber'), $course->idnumber === '' ? get_string('noidnumber', $component) : s($course->idnumber)],
    [get_string('shortname'), format_string($course->shortname)],
    [get_string('category'), s($categories[$course->category] ?? '')],
    [get_string('visible'), $course->visible ? get_string('yes') : get_string('no')],
    ['', html_writer::link(
        new moodle_url('/course/view.php', ['id' => $course->id]),
        get_string('opencourse', $component),
        ['target' => '_blank']
    )],
];
echo html_writer::table($details);

if (!$methods) {
    echo $OUTPUT->notification(get_string('nomethods', $component), 'info');
    echo $OUTPUT->footer();
    exit;
}

// 2. One feed card per method.
$reviews = [];
foreach ($methods as $method) {
    $reader = new feed_reader($method);
    $review = course_review::review($course, $method, $reader);
    $reviews[$method] = $review;
    $lines = [];
    if (!$review->feed->available) {
        $lines[] = $OUTPUT->notification(
            get_string('feedunavailable', $component, ['method' => $methodname($method), 'feed' => $reader->describe()]),
            'error',
            false
        );
    } else {
        $lines[] = get_string('feedconnected', $component);
        if ($course->idnumber === '') {
            $lines[] = get_string('courseemptyidnumber', $component);
        } else if ($review->feed->course_in_feed()) {
            $lines[] = get_string('courseinfeed', $component, $review->feed->rows);
        } else {
            $lines[] = get_string('coursenotinfeed', $component);
        }
    }
    $lines[] = get_string('lastsync', $component, $date(course_data::last_sync($method)));
    $title = html_writer::tag('h4', s($methodname($method) . ' · ' . $reader->describe()));
    echo $OUTPUT->box($title . html_writer::alist($lines, ['class' => 'list-unstyled']), 'generalbox');
}

// Filters for the people lists.
$baseurl = new moodle_url('/local/rvc_enrolment_management/course.php', ['id' => $course->id]);
$methodoptions = [];
foreach ($methods as $method) {
    $methodoptions[$method] = $methodname($method);
}
echo $OUTPUT->single_select(
    new moodle_url($baseurl, array_filter(['role' => $rolefilter])),
    'method',
    $methodoptions,
    $methodfilter,
    ['' => get_string('allmethods', $component)]
);
$roleoptions = array_unique(array_values($roles));
sort($roleoptions);
echo $OUTPUT->single_select(
    new moodle_url($baseurl, array_filter(['method' => $methodfilter])),
    'role',
    $roleoptions ? array_combine($roleoptions, $roleoptions) : [],
    $rolefilter,
    ['' => get_string('allroles', $component)]
);

$matches = fn(string $method, int $userid) => ($methodfilter === '' || $methodfilter === $method)
    && ($rolefilter === '' || ($roles[$userid] ?? '') === $rolefilter);
$person = fn(stdClass $row) => html_writer::link(
    new moodle_url('/user/view.php', ['id' => $row->userid, 'course' => $course->id]),
    fullname($row)
) . html_writer::div(s($row->username), 'small text-muted');

// 3. Not in the feed: can be suspended. Nothing is ticked at the start.
echo $OUTPUT->heading(get_string('notlistedheading', $component), 3);
$table = new html_table();
$master = new \core\output\checkbox_toggleall('rvcem-suspend', true, [
    'id' => 'rvcem-selectall',
    'name' => 'selectall',
    'label' => get_string('selectall'),
    'labelclasses' => 'visually-hidden',
]);
$table->head = [
    $OUTPUT->render($master),
    get_string('name'),
    get_string('account', $component),
    get_string('method', $component),
    get_string('roles'),
    get_string('lastcourseaccess'),
    get_string('otheractive', $component),
];
foreach ($reviews as $method => $review) {
    foreach ($review->notlisted as $ueid => $row) {
        if (!$matches($method, $row->userid)) {
            continue;
        }
        $tick = new \core\output\checkbox_toggleall('rvcem-suspend', false, [
            'id' => 'rvcem-ue' . $ueid,
            'name' => 'ueids[]',
            'value' => $ueid,
            'label' => fullname($row),
            'labelclasses' => 'visually-hidden',
        ]);
        $account = $row->userdeleted ? get_string('accountdeleted', $component)
            : ($row->usersuspended ? get_string('accountsuspended', $component) : '');
        $table->data[] = [
            $OUTPUT->render($tick),
            $person($row),
            $account === '' ? '' : html_writer::span($account, 'badge bg-warning text-dark'),
            $methodname($method),
            s($roles[$row->userid] ?? ''),
            $date((int) ($lastaccess[$row->userid] ?? 0)),
            s(implode(', ', array_map($methodname, array_unique($row->otheractive)))),
        ];
    }
}
if (!$table->data) {
    echo $OUTPUT->notification(get_string('nonenotlisted', $component), 'info', false);
} else {
    $confirmurl = new moodle_url('/local/rvc_enrolment_management/confirm.php', ['courseid' => $course->id]);
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $confirmurl->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::table($table);
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'class' => 'btn btn-primary',
        'value' => get_string('suspendselected', $component),
    ]);
    echo html_writer::end_tag('form');
}

// 4. Still in the feed: cannot be suspended.
$listed = new html_table();
$listed->head = [get_string('name'), get_string('method', $component), get_string('roles'), get_string('status')];
$listed->data = [];
foreach ($reviews as $method => $review) {
    foreach ($review->listed as $row) {
        if ($matches($method, $row->userid)) {
            $listed->data[] = [
                $person($row),
                $methodname($method),
                s($roles[$row->userid] ?? ''),
                get_string('stillinfeed', $component),
            ];
        }
    }
}
echo $OUTPUT->heading(get_string('listedheading', $component, count($listed->data)), 3);
if ($listed->data) {
    echo html_writer::table($listed);
}

// 5. Suspended by this tool, and 6. suspended by something else (information only).
$delay = (int) get_config($component, 'deletiondelaydays');
$tool = new html_table();
$tool->head = [
    get_string('name'),
    get_string('method', $component),
    get_string('suspendedon', $component),
    get_string('suspendedby', $component),
    get_string('reason', $component),
    get_string('deletablefrom', $component),
];
$tool->data = [];
$elsewhere = new html_table();
$elsewhere->head = [get_string('name'), get_string('method', $component), get_string('lastmodified')];
$elsewhere->data = [];
foreach ($methods as $method) {
    if ($methodfilter !== '' && $methodfilter !== $method) {
        continue;
    }
    $suspended = course_data::suspended($course->id, $method);
    foreach ($suspended['tool'] as $row) {
        $admin = fullname(username_load_fields_from_object(new stdClass(), $row, 'admin'));
        $tool->data[] = [
            $person($row),
            $methodname($method),
            $date((int) $row->timesuspended),
            s($admin),
            s((string) $row->reason),
            $date((int) $row->timesuspended + $delay * DAYSECS),
        ];
    }
    foreach ($suspended['elsewhere'] as $row) {
        $elsewhere->data[] = [$person($row), $methodname($method), $date((int) $row->timemodified)];
    }
}
echo $OUTPUT->heading(get_string('toolheading', $component, count($tool->data)), 3);
if ($tool->data) {
    echo html_writer::table($tool);
}
echo $OUTPUT->heading(get_string('elsewhereheading', $component, count($elsewhere->data)), 3);
if ($elsewhere->data) {
    echo html_writer::table($elsewhere);
}

echo $OUTPUT->footer();

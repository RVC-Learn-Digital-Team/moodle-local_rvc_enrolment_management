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

use local_rvc_enrolment_management\form\people_filter_form;
use local_rvc_enrolment_management\local\course_data;
use local_rvc_enrolment_management\local\course_review;
use local_rvc_enrolment_management\local\feed_reader;
use local_rvc_enrolment_management\local\people_filter;
use local_rvc_enrolment_management\local\tool_suspensions;
use local_rvc_enrolment_management\local\upload;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$id = required_param('id', PARAM_INT);
$uploadkey = optional_param('upload', '', PARAM_ALPHANUM);
$filters = [
    'method' => optional_param('method', '', PARAM_ALPHANUMEXT),
    'role' => optional_param('role', '', PARAM_TEXT),
    'account' => optional_param('account', '', PARAM_ALPHA),
    'person' => optional_param('person', '', PARAM_TEXT),
];

admin_externalpage_setup(
    'local_rvc_enrolment_management_manage',
    '',
    array_filter(['id' => $id, 'upload' => $uploadkey] + $filters),
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
$keepupload = array_filter(['upload' => $uploadkey]);

// Filters for the people lists. They live in the page address, so the Back button keeps them.
$baseurl = new moodle_url('/local/rvc_enrolment_management/course.php', ['id' => $course->id] + $keepupload);
$methodoptions = [];
foreach ($methods as $method) {
    $methodoptions[$method] = $methodname($method);
}
$roleoptions = array_unique(array_values($roles));
sort($roleoptions);
$filterform = new people_filter_form($baseurl, ['methods' => $methodoptions, 'roles' => $roleoptions]);
$filterform->set_data($filters);
if ($filterform->is_cancelled()) {
    redirect($baseurl);
}
if ($data = $filterform->get_data()) {
    $params = [];
    foreach (people_filter::NAMES as $name) {
        if (!empty($data->$name)) {
            $params[$name] = $data->$name;
        }
    }
    redirect(new moodle_url($baseurl, $params));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($course->fullname));
echo html_writer::link(new moodle_url('/local/rvc_enrolment_management/index.php'), get_string('backtosearch', $component));

// An uploaded list limits every list on this page to its people.
$upload = null;
$uploadusers = null;
if ($uploadkey !== '') {
    $upload = upload::load($uploadkey, $course->id);
    if (!$upload) {
        $uploadurl = new moodle_url('/local/rvc_enrolment_management/upload.php');
        echo $OUTPUT->notification(get_string('uploadexpired', $component, $uploadurl->out()), 'error');
        echo $OUTPUT->footer();
        exit;
    }
    $uploadusers = array_flip($upload['userids']);
    $everyone = new moodle_url('/local/rvc_enrolment_management/course.php', ['id' => $course->id]);
    echo $OUTPUT->notification(get_string('uploadedlist', $component, [
        'count' => count($upload['userids']),
        'filename' => s($upload['filename']),
        'url' => $everyone->out(),
    ]), 'info', false);
}

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
        // Which stage failed, without any connection details.
        $lines[] = get_string('feedreason_' . $review->feed->reason, $component, s($review->feed->errorclass));
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

$filterform->display();

$matches = fn(string $method, stdClass $row) => ($uploadusers === null || isset($uploadusers[$row->userid]))
    && people_filter::matches($method, $row, $roles[$row->userid] ?? '', $filters);
$person = fn(stdClass $row) => html_writer::link(
    new moodle_url('/user/view.php', ['id' => $row->userid, 'course' => $course->id]),
    fullname($row),
    ['target' => '_blank']
) . html_writer::div(s($row->username), 'small text-muted');
$account = function (stdClass $row) use ($component): string {
    $state = people_filter::account($row);
    return $state === 'active' ? '' : html_writer::span(get_string('account' . $state, $component), 'badge bg-warning text-dark');
};

// Each list can be expanded or collapsed; only "can be suspended" starts expanded.
$section = fn(string $title, string $body, bool $open) => html_writer::tag(
    'details',
    html_writer::tag('summary', html_writer::tag('h3', $title, ['class' => 'h4 d-inline'])) . $body,
    ['class' => 'mb-4'] + ($open ? ['open' => 'open'] : [])
);

// Long lists repeat their buttons above the table.
$form = function (moodle_url $url, html_table $table, string $buttons): string {
    $top = count($table->data) > 10 ? html_writer::div($buttons, 'mb-2') : '';
    return html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)])
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
        . $top . html_writer::table($table) . $buttons
        . html_writer::end_tag('form');
};

// 3. Not in the feed: can be suspended. Nothing is ticked at the start.
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
$table->data = [];
$hasdeleted = false;
foreach ($reviews as $method => $review) {
    foreach ($review->notlisted as $ueid => $row) {
        if (!$matches($method, $row)) {
            continue;
        }
        $hasdeleted = $hasdeleted || people_filter::account($row) === 'deleted';
        $tick = new \core\output\checkbox_toggleall('rvcem-suspend', false, [
            'id' => 'rvcem-ue' . $ueid,
            'name' => 'ueids[]',
            'value' => $ueid,
            'label' => fullname($row),
            'labelclasses' => 'visually-hidden',
        ]);
        $table->data[] = [
            $OUTPUT->render($tick),
            $person($row),
            $account($row),
            $methodname($method),
            s($roles[$row->userid] ?? ''),
            $date((int) ($lastaccess[$row->userid] ?? 0)),
            s(implode(', ', array_map($methodname, array_unique($row->otheractive)))),
        ];
    }
}
if (!$table->data) {
    $body = $OUTPUT->notification(get_string('nonenotlisted', $component), 'info', false);
} else {
    $confirmurl = new moodle_url('/local/rvc_enrolment_management/confirm.php', ['courseid' => $course->id] + $keepupload);
    $button = html_writer::empty_tag('input', [
        'type' => 'submit',
        'class' => 'btn btn-primary',
        'value' => get_string('suspendselected', $component),
    ]);
    // Deleted accounts' enrolments can be deleted straight away, from the same ticks.
    if ($hasdeleted) {
        $deleteurl = new moodle_url('/local/rvc_enrolment_management/action.php', ['courseid' => $course->id] + $keepupload);
        $button .= ' ' . html_writer::tag('button', get_string('deletedeletedselected', $component), [
            'type' => 'submit',
            'formaction' => $deleteurl->out(false),
            'name' => 'action',
            'value' => tool_suspensions::DELETE_DELETED,
            'class' => 'btn btn-danger ms-2',
        ]);
    }
    $body = $form($confirmurl, $table, $button);
}
echo $section(get_string('notlistedheading', $component, count($table->data)), $body, true);

// 4. Still in the feed: cannot be suspended.
$listed = new html_table();
$listed->head = [
    get_string('name'),
    get_string('account', $component),
    get_string('method', $component),
    get_string('roles'),
    get_string('status'),
];
$listed->data = [];
foreach ($reviews as $method => $review) {
    foreach ($review->listed as $row) {
        if ($matches($method, $row)) {
            $listed->data[] = [
                $person($row),
                $account($row),
                $methodname($method),
                s($roles[$row->userid] ?? ''),
                get_string('stillinfeed', $component),
            ];
        }
    }
}
echo $section(
    get_string('listedheading', $component, count($listed->data)),
    $listed->data ? html_writer::table($listed) : '',
    false
);

// 5. Suspended by this tool (re-activate or delete), and 6. suspended by something else (delete).
$actionurl = new moodle_url('/local/rvc_enrolment_management/action.php', ['courseid' => $course->id] + $keepupload);
$deletable = function (int $suspended, stdClass $row) use ($date): string {
    $from = people_filter::account($row) === 'deleted' ? 0 : tool_suspensions::deletable_from($suspended);
    return $from ? $date($from) : get_string('now');
};
$tickbox = fn(string $group, int $value, stdClass $row) => $OUTPUT->render(new \core\output\checkbox_toggleall($group, false, [
    'id' => $group . $value,
    'name' => 'ids[]',
    'value' => $value,
    'label' => fullname($row),
    'labelclasses' => 'visually-hidden',
]));
$selectall = fn(string $group) => $OUTPUT->render(new \core\output\checkbox_toggleall($group, true, [
    'id' => $group . '-selectall',
    'name' => $group . '-selectall',
    'label' => get_string('selectall'),
    'labelclasses' => 'visually-hidden',
]));
$actionbuttons = function (array $buttons) use ($component): string {
    $html = '';
    foreach ($buttons as $action => $class) {
        $html .= html_writer::tag('button', get_string($action . 'selected', $component), [
            'type' => 'submit',
            'name' => 'action',
            'value' => $action,
            'class' => "btn $class me-2",
        ]);
    }
    return $html;
};

$tool = new html_table();
$tool->head = [
    $selectall('rvcem-tool'),
    get_string('name'),
    get_string('account', $component),
    get_string('method', $component),
    get_string('suspendedon', $component),
    get_string('suspendedby', $component),
    get_string('reason', $component),
    get_string('deletablefrom', $component),
];
$tool->data = [];
$elsewhere = new html_table();
$elsewhere->head = [
    $selectall('rvcem-elsewhere'),
    get_string('name'),
    get_string('account', $component),
    get_string('method', $component),
    get_string('lastchanged', $component),
    get_string('deletablefrom', $component),
];
$elsewhere->data = [];
foreach ($methods as $method) {
    $suspended = course_data::suspended($course->id, $method);
    foreach ($suspended['tool'] as $row) {
        if (!$matches($method, $row)) {
            continue;
        }
        $admin = fullname(username_load_fields_from_object(new stdClass(), $row, 'admin'));
        $tool->data[] = [
            $tickbox('rvcem-tool', (int) $row->suspensionid, $row),
            $person($row),
            $account($row),
            $methodname($method),
            $date((int) $row->timesuspended),
            s($admin),
            s((string) $row->reason),
            $deletable((int) $row->timesuspended, $row),
        ];
    }
    foreach ($suspended['elsewhere'] as $ueid => $row) {
        if (!$matches($method, $row)) {
            continue;
        }
        $elsewhere->data[] = [
            $tickbox('rvcem-elsewhere', (int) $ueid, $row),
            $person($row),
            $account($row),
            $methodname($method),
            $date((int) $row->timemodified),
            $deletable((int) $row->timemodified, $row),
        ];
    }
}
echo $section(
    get_string('toolheading', $component, count($tool->data)),
    $tool->data ? $form($actionurl, $tool, $actionbuttons(['reactivate' => 'btn-secondary', 'delete' => 'btn-danger'])) : '',
    false
);
$elsewherebody = '';
if ($elsewhere->data) {
    $elsewherebody = html_writer::tag('p', get_string('elsewhereintro', $component))
        . $form($actionurl, $elsewhere, $actionbuttons(['deleteelsewhere' => 'btn-danger']));
}
echo $section(get_string('elsewhereheading', $component, count($elsewhere->data)), $elsewherebody, false);

// 7. Uploaded list only: people who can't be suspended here, with the reason. Unreadable or
// unmatched lines have no person, so they are left out while a person or account filter is set.
if ($upload) {
    $notsuspendable = new html_table();
    $notsuspendable->head = [get_string('line', $component), get_string('name'), get_string('status')];
    $notsuspendable->data = [];
    if ($filters['person'] === '' && $filters['account'] === '') {
        foreach ($upload['problems'] as $problem) {
            $reason = get_string('problem_' . $problem['reason'], $component);
            $notsuspendable->data[] = [$problem['line'], s($problem['value']), $reason];
        }
    }
    $missing = array_diff($upload['userids'], course_data::users_with_methods($course->id));
    foreach ($DB->get_records_list('user', 'id', $missing, 'lastname, firstname') as $user) {
        $user->userid = $user->id;
        $user->usersuspended = $user->suspended;
        $user->userdeleted = $user->deleted;
        if (people_filter::matches('', $user, $roles[$user->id] ?? '', ['method' => ''] + $filters)) {
            $notsuspendable->data[] = ['', $person($user), get_string('problem_noenrolment', $component)];
        }
    }
    echo $section(
        get_string('notsuspendableheading', $component, count($notsuspendable->data)),
        $notsuspendable->data ? html_writer::table($notsuspendable) : '',
        false
    );
}

echo $OUTPUT->footer();

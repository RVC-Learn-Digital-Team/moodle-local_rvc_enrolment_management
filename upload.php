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
 * Upload enrolment list: a CSV of people for one course, then the course page for just them.
 *
 * @package   local_rvc_enrolment_management
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_rvc_enrolment_management\form\upload_form;
use local_rvc_enrolment_management\local\upload;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_rvc_enrolment_management_upload');
require_admin();

$component = 'local_rvc_enrolment_management';
$form = new upload_form();
$error = null;
if ($data = $form->get_data()) {
    $parsed = upload::parse((string) $form->get_file_content('csvfile'), $data->encoding, $data->delimiter);
    if ($parsed['error'] === null) {
        $key = upload::store($parsed, (string) $form->get_new_filename('csvfile'));
        redirect(new moodle_url('/local/rvc_enrolment_management/course.php', ['id' => $parsed['course']->id, 'upload' => $key]));
    }
    $error = $parsed['error'];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('uploadlist', $component));
if ($error) {
    echo $OUTPUT->notification(get_string($error, $component), 'error');
}
echo html_writer::tag('p', get_string('uploadintro', $component));
$form->display();
echo $OUTPUT->footer();

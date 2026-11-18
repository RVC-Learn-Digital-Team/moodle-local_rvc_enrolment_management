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

namespace local_rvc_enrolment_management\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');
require_once($CFG->libdir . '/csvlib.class.php');

/**
 * Upload a CSV list of people for one course, in the style of Moodle's own upload pages.
 *
 * @package   local_rvc_enrolment_management
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upload_form extends \moodleform {
    /**
     * Defines the form.
     */
    protected function definition() {
        $mform = $this->_form;
        $component = 'local_rvc_enrolment_management';

        $options = ['accepted_types' => ['.csv', '.txt']];
        $mform->addElement('filepicker', 'csvfile', get_string('csvfile', $component), null, $options);
        $mform->addRule('csvfile', null, 'required');
        $mform->addHelpButton('csvfile', 'csvfile', $component);

        $mform->addElement('select', 'delimiter', get_string('csvdelimiter', $component), \csv_import_reader::get_delimiter_list());
        $mform->setDefault('delimiter', 'comma');

        $mform->addElement('select', 'encoding', get_string('encoding', $component), \core_text::get_encodings());
        $mform->setDefault('encoding', 'UTF-8');

        $this->add_action_buttons(false, get_string('uploadlist', $component));
    }
}

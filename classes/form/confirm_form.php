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

/**
 * Confirms a suspension: optional reason, and an acknowledgement when a batch is large.
 *
 * Custom data: ueids (int[] eligible user enrolment ids), warnings (string[] warning lines).
 *
 * @package   local_rvc_enrolment_management
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class confirm_form extends \moodleform {
    /**
     * Defines the form.
     */
    protected function definition() {
        $mform = $this->_form;
        $component = 'local_rvc_enrolment_management';
        $ueids = $this->_customdata['ueids'];
        $warnings = $this->_customdata['warnings'];

        $mform->addElement('hidden', 'uelist', implode(',', $ueids));
        $mform->setType('uelist', PARAM_SEQUENCE);

        $mform->addElement('textarea', 'reason', get_string('reason', $component), ['rows' => 3, 'cols' => 60]);
        $mform->setType('reason', PARAM_TEXT);
        $mform->addHelpButton('reason', 'reason', $component);

        if ($warnings) {
            foreach ($warnings as $warning) {
                $mform->addElement('static', '', '', \html_writer::div(s($warning), 'alert alert-warning'));
            }
            $mform->addElement('advcheckbox', 'acknowledge', '', get_string('acknowledgewarning', $component));
        }

        $this->add_action_buttons(true, get_string('suspendcount', $component, count($ueids)));
    }

    /**
     * Requires the acknowledgement when there are warnings.
     *
     * @param array $data
     * @param array $files
     * @return array errors
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if ($this->_customdata['warnings'] && empty($data['acknowledge'])) {
            $errors['acknowledge'] = get_string('acknowledgerequired', 'local_rvc_enrolment_management');
        }
        return $errors;
    }
}

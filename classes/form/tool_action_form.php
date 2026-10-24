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
 * Confirms re-activating or deleting suspended enrolments. Deletions take an optional reason.
 *
 * Custom data: action (string), ids (int[] eligible ids), label (button text).
 *
 * @package   local_rvc_enrolment_management
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_action_form extends \moodleform {
    /**
     * Defines the form.
     */
    protected function definition() {
        $mform = $this->_form;
        $component = 'local_rvc_enrolment_management';
        $mform->addElement('hidden', 'action', $this->_customdata['action']);
        $mform->setType('action', PARAM_ALPHA);
        $mform->addElement('hidden', 'idlist', implode(',', $this->_customdata['ids']));
        $mform->setType('idlist', PARAM_SEQUENCE);
        if ($this->_customdata['action'] !== 'reactivate') {
            $mform->addElement('textarea', 'reason', get_string('deletereason', $component), ['rows' => 3, 'cols' => 60]);
            $mform->setType('reason', PARAM_TEXT);
            $mform->addHelpButton('reason', 'deletereason', $component);
        }
        $this->add_action_buttons(true, $this->_customdata['label']);
    }
}

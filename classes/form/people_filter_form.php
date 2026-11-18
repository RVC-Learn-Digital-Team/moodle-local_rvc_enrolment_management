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
 * Filters for the course page's people lists. Submitting redirects with the filters as plain
 * parameters, as the course search does.
 *
 * Custom data: methods (method => name), roles (role names).
 *
 * @package   local_rvc_enrolment_management
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class people_filter_form extends \moodleform {
    /**
     * Defines the filters.
     */
    protected function definition() {
        $mform = $this->_form;
        $component = 'local_rvc_enrolment_management';

        $methods = ['' => get_string('allmethods', $component)] + $this->_customdata['methods'];
        $mform->addElement('select', 'method', get_string('method', $component), $methods);

        $roles = $this->_customdata['roles'];
        $roles = ['' => get_string('allroles', $component)] + ($roles ? array_combine($roles, $roles) : []);
        $mform->addElement('select', 'role', get_string('roles'), $roles);

        $accounts = ['' => get_string('allaccounts', $component)];
        foreach (\local_rvc_enrolment_management\local\people_filter::ACCOUNTS as $account) {
            $accounts[$account] = get_string('account' . $account, $component);
        }
        $mform->addElement('select', 'account', get_string('account', $component), $accounts);

        $mform->addElement('text', 'person', get_string('filterperson', $component));
        $mform->setType('person', PARAM_TEXT);
        $mform->addHelpButton('person', 'filterperson', $component);

        $buttons = [
            $mform->createElement('submit', 'submitbutton', get_string('filter')),
            $mform->createElement('cancel', 'cancel', get_string('resetfilters', $component)),
        ];
        $mform->addGroup($buttons, 'buttonar', '', ' ', false);
    }
}

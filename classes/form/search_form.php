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
 * Course search filters. Submitting redirects to the page with the filters as plain parameters.
 *
 * @package   local_rvc_enrolment_management
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_form extends \moodleform {
    /**
     * Defines the filters.
     */
    protected function definition() {
        $mform = $this->_form;
        $component = 'local_rvc_enrolment_management';

        $mform->addElement('text', 'courseids', get_string('filtercourseids', $component));
        $mform->setType('courseids', PARAM_TEXT);
        $mform->addHelpButton('courseids', 'filtercourseids', $component);

        $mform->addElement('text', 'coursename', get_string('filtercoursename', $component));
        $mform->setType('coursename', PARAM_TEXT);
        $mform->addHelpButton('coursename', 'filtercoursename', $component);

        $mform->addElement('text', 'idnumber', get_string('filteridnumber', $component));
        $mform->setType('idnumber', PARAM_TEXT);
        $mform->addHelpButton('idnumber', 'filteridnumber', $component);

        $categories = [0 => get_string('allcategories', $component)] + \core_course_category::make_categories_list();
        $mform->addElement('select', 'category', get_string('filtercategory', $component), $categories);
        $mform->setType('category', PARAM_INT);
        $mform->addHelpButton('category', 'filtercategory', $component);

        $methods = [
            '' => get_string('eithermethod', $component),
            'database' => get_string('method_database', $component),
            'databaseextendedv2' => get_string('method_databaseextendedv2', $component),
        ];
        $mform->addElement('select', 'method', get_string('filtermethod', $component), $methods);
        $mform->setType('method', PARAM_ALPHANUMEXT);

        $buttons = [
            $mform->createElement('submit', 'submitbutton', get_string('search')),
            $mform->createElement('cancel', 'cancel', get_string('resetfilters', $component)),
        ];
        $mform->addGroup($buttons, 'buttonar', '', ' ', false);
    }
}

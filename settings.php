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
 * Admin entries: a Settings page and the Suspensions page, under Local plugins only.
 *
 * @package   local_rvc_enrolment_management
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $component = 'local_rvc_enrolment_management';

    $ADMIN->add('localplugins', new admin_category($component, new lang_string('pluginname', $component)));

    $settings = new admin_settingpage($component . '_settings', new lang_string('settings', $component));
    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_configtext(
            $component . '/deletiondelaydays',
            new lang_string('deletiondelaydays', $component),
            new lang_string('deletiondelaydays_desc', $component),
            3,
            PARAM_INT
        ));
        $settings->add(new admin_setting_configtext(
            $component . '/warnthreshold',
            new lang_string('warnthreshold', $component),
            new lang_string('warnthreshold_desc', $component),
            50,
            PARAM_INT
        ));
    }
    $ADMIN->add($component, $settings);

    $ADMIN->add($component, new admin_externalpage(
        $component . '_manage',
        new lang_string('manageenrolments', $component),
        new moodle_url('/local/rvc_enrolment_management/index.php'),
        'moodle/site:config'
    ));
}

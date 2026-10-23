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
 * English strings for local_rvc_enrolment_management.
 *
 * @package   local_rvc_enrolment_management
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['account'] = 'Account';
$string['accountdeleted'] = 'Deleted account';
$string['accountsuspended'] = 'Suspended account';
$string['acknowledgerequired'] = 'Tick this box to confirm you have read the warning.';
$string['acknowledgewarning'] = 'I understand; suspend them anyway';
$string['activesuspended'] = '{$a->active} active, {$a->suspended} suspended';
$string['allcategories'] = 'All categories';
$string['allmethods'] = 'All methods';
$string['allroles'] = 'All roles';
$string['backtosearch'] = 'Back to course search';
$string['batchwarning'] = 'You are suspending {$a->selected} of {$a->active} active {$a->method} enrolments in this course ({$a->percent}%), which is above the warning level.';
$string['confirmsuspension'] = 'Confirm suspension';
$string['courseemptyidnumber'] = 'This course has no ID number, so no feed can list anyone for it.';
$string['courseid'] = 'Course ID';
$string['courseinfeed'] = 'This course is in the current feed ({$a} rows).';
$string['coursenotinfeed'] = 'This course is not in the current feed. That is normal for previous academic years.';
$string['deletablefrom'] = 'Can be deleted from';
$string['deletiondelaydays'] = 'Days before deletion';
$string['deletiondelaydays_desc'] = 'How many days after this tool suspends an enrolment before an administrator may delete it.';
$string['eithermethod'] = 'Database or Extended';
$string['elsewhereheading'] = 'Already suspended by something else ({$a})';
$string['feedconnected'] = 'Feed connected.';
$string['feedunavailable'] = 'Enrolment management for {$a->method} is suspended until access to {$a->feed} is restored.';
$string['filtercategory'] = 'Category';
$string['filtercategory_help'] = 'Shows courses in this category and all its subcategories.';
$string['filtercourseids'] = 'Course ID';
$string['filtercourseids_help'] = 'One or more course ID numbers, separated by spaces or commas. A course matches if its ID is any of them.';
$string['filtercoursename'] = 'Course name';
$string['filtercoursename_help'] = 'Words separated by spaces. Every word must appear in the course full name or short name. Capitals are ignored.';
$string['filteridnumber'] = 'ID number';
$string['filteridnumber_help'] = 'Words separated by spaces, for example "1vet 2026". Every word must appear in the course ID number. Capitals are ignored.';
$string['filtermethod'] = 'Enrolment method';
$string['lastsync'] = 'Sync last ran: {$a}';
$string['listedheading'] = 'Still in the feed: can\'t be suspended ({$a})';
$string['manageenrolments'] = 'Manage enrolments';
$string['method'] = 'Method';
$string['method_database'] = 'Database';
$string['method_databaseextendedv2'] = 'Extended';
$string['noidnumber'] = '(none)';
$string['nomethods'] = 'This course has no Database or Extended enrolment method.';
$string['nonenotlisted'] = 'Nobody here can be suspended: everyone matching these filters is still in the feed, or the feed could not be read.';
$string['nothingtosuspend'] = 'None of the selected enrolments can be suspended now.';
$string['notlistedheading'] = 'Not in the feed: can be suspended';
$string['opencourse'] = 'Open course';
$string['otheractive'] = 'Other active enrolments';
$string['pluginname'] = 'RVC Enrolment Management';
$string['privacy:metadata:suspension'] = 'Suspensions of database and extended database enrolments made by this tool, with any later re-activation or deletion.';
$string['privacy:metadata:suspension:courseid'] = 'The course the enrolment belongs to.';
$string['privacy:metadata:suspension:deletedby'] = 'The administrator who deleted the enrolment.';
$string['privacy:metadata:suspension:enrol'] = 'The enrolment method.';
$string['privacy:metadata:suspension:reactivatedby'] = 'The administrator who re-activated the enrolment.';
$string['privacy:metadata:suspension:reason'] = 'The reason the administrator gave for the suspension.';
$string['privacy:metadata:suspension:suspendedby'] = 'The administrator who suspended the enrolment.';
$string['privacy:metadata:suspension:timedeleted'] = 'When the enrolment was deleted.';
$string['privacy:metadata:suspension:timereactivated'] = 'When the enrolment was re-activated.';
$string['privacy:metadata:suspension:timesuspended'] = 'When the enrolment was suspended.';
$string['privacy:metadata:suspension:userid'] = 'The person whose enrolment was suspended.';
$string['reason'] = 'Reason';
$string['reason_help'] = 'Optional. Stored with each suspension and shown on the course page.';
$string['resetfilters'] = 'Reset';
$string['selectsomeone'] = 'Select at least one person to suspend.';
$string['settings'] = 'Settings';
$string['skipped_feedunavailable'] = '{$a}: the feed could not be read, so nothing was changed.';
$string['skipped_listed'] = '{$a}: listed in the feed again, so not suspended.';
$string['skipped_notactive'] = '{$a}: no longer an active Database or Extended enrolment in this course.';
$string['skippedheading'] = 'Not suspended ({$a})';
$string['stillinfeed'] = 'Still in the feed. Remove it there first.';
$string['suspendcount'] = 'Suspend {$a} enrolments';
$string['suspendedby'] = 'Suspended by';
$string['suspendeddone'] = '{$a} enrolments suspended.';
$string['suspendedon'] = 'Suspended on';
$string['suspendselected'] = 'Suspend selected';
$string['suspensionresults'] = 'Suspension results';
$string['toolheading'] = 'Suspended by this tool ({$a})';
$string['unknownenrolment'] = 'Enrolment {$a}';
$string['warnthreshold'] = 'Batch warning threshold (%)';
$string['warnthreshold_desc'] = 'Ask for an extra confirmation when a selection is more than this percentage of a course\'s active enrolments for that method. This is a warning only.';

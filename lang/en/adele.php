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
 * Plugin strings are defined here.
 *
 * @package     mod_adele
 * @category    string
 * @copyright   2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Learning path';
$string['modulenameplural'] = 'Learning paths';
$string['modulename'] = 'Learning path';
$string['adelename'] = 'Learning path';
$string['adelename_help'] = 'Help for Learning path';
$string['adelefieldset'] = 'Learning path Settings';
$string['adelesettings'] = 'Learning path Settings';
$string['adele:readinstance'] = 'Is allowed to view instances of the learning path plugin';
$string['pluginadministration'] = 'Learning path Plugin Administration';
$string['adele:addinstance'] = 'Add Learning path Instance';

// Capabilities.
$string['adele:addlearningpath'] = 'Can add learning paths';
$string['mod/adele:seelearningpath'] = 'Can see learning paths';

// Mform.
$string['mform_select_learningpath'] = 'Chosen Learning Path';
$string['mform_select_view'] = 'Choose view';
$string['mform_select_userlist'] = 'Choose user list option';
$string['mform_select_participantslist'] = 'Choose an option for how people get subscribed to the learning path';

// Mform options.
$string['mform_options_view_top_level'] = 'Show Learning path on top level';
$string['mform_options_view_floor_level'] = 'Show Learning path on floor level';
$string['mform_options_userlist_all'] = 'Everyone sees overview of all subscribed participants results';
$string['mform_options_userlist_only'] = 'Everyone sees only their own results';
$string['mform_options_participantslist_this_course'] = 'Everyone who is subscribed to that course';
$string['mform_options_participantslist_starting_courses'] = 'Everyone who is subscribed to at least one starting node';
$string['mform_options_create_learningpath'] = 'Create learning path';
$string['mform_options_link_create_learningpath'] = 'Link to learning path creation';
$string['mform_options_no_selection'] = 'No selection';
$string['mform_options_required'] = 'required';

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
 * @copyright   2026 Ralf Erlebach
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['adele:addinstance'] = 'Add Learning path Instance';
$string['adele:addlearningpath'] = 'Can add learning paths';
$string['adele:readinstance'] = 'Is allowed to view instances of the learning path plugin';
$string['adelefieldset'] = 'Learning path Settings';
$string['adelename'] = 'Learning path';
$string['adelename_help'] = 'Help for Learning path';
$string['adelesettings'] = 'Learning path Settings';
$string['completionlearningpathfinished'] = 'Learningpath completion';
$string['completionlearningpathfinished:desc'] = 'Activity completion will be triggered if learning path is finished';
$string['error_adaptivequiz_mismatch'] = 'The adaptive quiz in the course does not match the one referenced in the learning path.';
$string['error_diverse_scales'] = 'Different scales were referenced. Please fix this.';
$string['error_multiple_adaptivequiz'] = 'More than one adaptive quiz was found in the course. Only one is allowed when a learning path is used.';
$string['learningpathdeleted'] = 'The learning path this activity embedded has been deleted.';
$string['mform_options_create_learningpath'] = 'Create new learning path';
$string['mform_options_hostenrolmentmode_hidden'] = 'hidden (as a suspended enrolment)';
$string['mform_options_hostenrolmentmode_none'] = 'no enrolment at all';
$string['mform_options_hostenrolmentmode_visible'] = 'automatic (with full access for participants)';
$string['mform_options_link_create_learningpath'] = 'Link to learning path creation';
$string['mform_options_no_selection'] = 'No selection';
$string['mform_options_participantslist_all_courses'] = 'for people enrolled in any course of the learning path';
$string['mform_options_participantslist_starting_courses'] = 'for people enrolled in a starting node';
$string['mform_options_participantslist_this_course'] = 'for people enrolled in this course';
$string['mform_options_required'] = 'required';
$string['mform_options_userlist_all'] = 'Results of all other participants';
$string['mform_options_userlist_only'] = 'Only own results';
$string['mform_options_view_floor_level'] = 'inside the activity';
$string['mform_options_view_top_level'] = 'directly on the course page';
$string['mform_select_hostenrolmentmode'] = 'Enrolment in this course';
$string['mform_select_hostenrolmentmode_help'] = 'Only relevant for the participant options "for people enrolled in a starting node" and "for people enrolled in any course of the learning path" — the option "for people enrolled in this course" always enrols actively into this course. Automatic: the person is actively enrolled and can access this course. Hidden: an enrolment record is kept (visible in the participant list and countable for reports/certificates) but the person gets no access. No enrolment: no enrolment is created here — only the learning path assignment itself happens.';
$string['mform_select_learningpath'] = 'Chosen Learning Path';
$string['mform_select_participantslist'] = 'Learning path enrolment';
$string['mform_select_userlist'] = 'Participant results';
$string['mform_select_view'] = 'Display learning path';
$string['mod/adele:seelearningpath'] = 'Can see learning paths';
$string['modulename'] = 'Learning path';
$string['modulenameplural'] = 'Learning paths';
$string['pluginadministration'] = 'Learning path Plugin Administration';
$string['pluginname'] = 'Learning path';

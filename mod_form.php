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
 * The main mod_adele configuration form.
 *
 * @package     mod_adele
 * @copyright   2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_adele\learning_paths;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/course/moodleform_mod.php');

/**
 * Module instance settings form.
 *
 * @package     mod_adele
 * @copyright   2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_adele_mod_form extends moodleform_mod {

    /**
     * Defines forms elements
     */
    public function definition() {
        global $CFG;

        $mform = $this->_form;

        // Adding the "general" fieldset, where all the common settings are shown.
        $mform->addElement('header', 'general', get_string('general', 'form'));

        // Adding the standard "name" field.
        $mform->addElement('text', 'name', get_string('adelename', 'mod_adele'), ['size' => '64']);

        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEANHTML);
        }

        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->addHelpButton('name', 'adelename', 'mod_adele');

        // Adding the standard "intro" and "introformat" fields.
        if ($CFG->branch >= 29) {
            $this->standard_intro_elements();
        } else {
            $this->add_intro_editor();
        }

        // Adding the rest of mod_adele settings, spreading all them into this fieldset
        // ... or adding more fieldsets ('header' elements) if needed for better logic.
        $mform->addElement('static', 'label1', 'adelesettings', get_string('adelesettings', 'mod_adele'));
        $mform->addElement('header', 'adelefieldset', get_string('adelefieldset', 'mod_adele'));

        $select = [];
        $records = learning_paths::get_learning_paths();
        foreach ($records as $record) {
            $select[$record['id']] = $record['name'];
        }

        $mform->addElement('autocomplete', 'learningpathid', get_string('mform_select_learningpath', 'mod_adele'), $select);

        $views = [
          1 => 'Show Learningpath on top level',
          2 => 'Show Learningpath on floor level',
        ];
        $mform->addElement('select', 'view', get_string('mform_select_view', 'mod_adele'), $views);

        $userlist = [
          1 => 'Students see overview of all students',
          2 => 'Students see only their own results',
        ];
        $mform->addElement('select', 'userlist', get_string('mform_select_userlist', 'mod_adele'), $userlist);

        $participantslist = [
          1 => 'Everyone who is subscribed who is subscribed to that course',
          2 => 'Everyone is subscribbed who is in one starting node',
        ];
        $mform->addElement('autocomplete', 'participantslist', get_string('mform_select_participantslist', 'mod_adele'),
            $participantslist);

        // Add standard elements.
        $this->standard_coursemodule_elements();

        // Add standard buttons.
        $this->add_action_buttons();
    }
}

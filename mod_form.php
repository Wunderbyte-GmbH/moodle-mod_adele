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

require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once($CFG->dirroot . '/local/adele/lib.php');

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

        $mform->addElement('header', 'adelefieldset', get_string('adelefieldset', 'mod_adele'));
        // Adding a link after the header.
        $editorurl = new moodle_url('/local/adele/index.php#/learningpaths/edit');

        $mform->addElement(
            'static',
            'link',
            get_string('mform_options_create_learningpath', 'mod_adele'),
            '<a class ="btn btn-secondary" href="'. $editorurl .'" target="blank">' .
            get_string('mform_options_link_create_learningpath', 'mod_adele') .
            '</a>'
        );

        $records = learning_paths::get_editable_learning_paths();

        $select = [];
        $select[0] = get_string('noselection', 'form');
        foreach ($records as $record) {
            $select[$record->id] = $record->name;
        }

        $options = [
          'multiple' => false,
          'noselectionstring' => get_string('mform_options_no_selection', 'mod_adele'),
          'tags' => false,
        ];

        $mform->addElement(
            'autocomplete',
            'learningpathid',
            get_string('mform_select_learningpath', 'mod_adele'),
            $select,
            $options
        );
        $mform->setDefault('learningpathid', 0);

        $mform->addRule('learningpathid', get_string('mform_options_required', 'mod_adele'), 'required', null, 'client');

        $views = [
          1 => get_string('mform_options_view_top_level', 'mod_adele'),
          2 => get_string('mform_options_view_floor_level', 'mod_adele'),
        ];
        $mform->addElement('select', 'view', get_string('mform_select_view', 'mod_adele'), $views);

        $userlist = [
          1 => get_string('mform_options_userlist_all', 'mod_adele'),
          2 => get_string('mform_options_userlist_only', 'mod_adele'),
        ];
        $mform->addElement('select', 'userlist', get_string('mform_select_userlist', 'mod_adele'), $userlist);

        $participantslist = [
          1 => get_string('mform_options_participantslist_this_course', 'mod_adele'),
          2 => get_string('mform_options_participantslist_starting_courses', 'mod_adele'),
        ];
        $mform->addElement(
            'autocomplete',
            'participantslist',
            get_string('mform_select_participantslist', 'mod_adele'),
            $participantslist,
            ['multiple' => true]
        );

        $mform->addRule('participantslist', get_string('mform_options_required', 'mod_adele'), 'required', null, 'client');

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Server-side validation
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        if (empty($data['learningpathid'])) {
            $errors['learningpathid'] = get_string('mform_options_required', 'mod_adele');
        }

        if (empty($data['participantslist'])) {
            $errors['participantslist'] = get_string('mform_options_required', 'mod_adele');
        }

        return $errors;
    }

    /**
     * Determines whether completion rules are enabled for this module.
     * @return array
     */
    public function add_completion_rules() {
        $mform = $this->_form;
        $mform->addElement(
            'checkbox',
            'completionlearningpathfinished',
            get_string('completionlearningpathfinished', 'mod_adele'),
            get_string('completionlearningpathfinished:desc', 'mod_adele')
        );

        return ['completionlearningpathfinished'];
    }

    /**
     * Determines whether completion rules are enabled for this module.
     * @param array $data Submitted form data.
     * @return bool
     */
    public function completion_rule_enabled($data) {
        return !empty($data['completionlearningpathfinished']);
    }
}

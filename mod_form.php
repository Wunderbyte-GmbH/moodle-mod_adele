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
        // Adding a link after the header.
        $mform->addElement('static', 'link',
          get_string('mform_options_create_learningpath', 'mod_adele'),
          '<a href="/local/adele/index.php#/learningpaths/edit" target="blank">' .
          get_string('mform_options_link_create_learningpath', 'mod_adele') .
          '</a>'
        );
        $mform->addElement('header', 'adelefieldset', get_string('adelefieldset', 'mod_adele'));

        $sessionvalue =
          isset($_SESSION[SESSION_KEY_ADELE]) ?
          $_SESSION[SESSION_KEY_ADELE] :
          null;

        $records = learning_paths::get_learning_paths(
            true,
            $sessionvalue
        );

        $select = [];
        foreach ($records['edit'] as $record) {
            $select[$record['id']] = $record['name'];
        }

        $options = [
          'noselectionstring' => get_string('mform_options_no_selection', 'mod_adele'),
          'tags' => false,
        ];

        $mform->addElement('autocomplete', 'learningpathid', get_string('mform_select_learningpath', 'mod_adele'), $select,
        $options);

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
        $mform->addElement('autocomplete', 'participantslist', get_string('mform_select_participantslist', 'mod_adele'),
            $participantslist, ['multiple' => true]);

        // Add standard elements.
        $this->standard_coursemodule_elements();

        // Add standard buttons.
        $this->add_action_buttons();
    }
}

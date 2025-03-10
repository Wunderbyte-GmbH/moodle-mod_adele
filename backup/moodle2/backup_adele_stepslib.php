<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * This file contains the backup activity for the assign module
 *
 * @package mod_adele
 * @copyright 2024 Wunderbyte GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Backus up adele module.
 */
class backup_adele_activity_structure_step extends backup_activity_structure_step {
    /**
     * Backs up nested element.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        // Define each element and its attributes.
        $adele = new backup_nested_element('adele', ['id'], [
            'course', 'name', 'learningpathid', 'view', 'userlist',
            'participantslist', 'timecreated', 'timemodified', 'intro', 'introformat', 'completionlearningpathfinished',
        ]);

        // Define sources.
        $adele->set_source_table('adele', ['id' => backup::VAR_ACTIVITYID]);

        // Define file annotations (if there are files to back up).
        $adele->annotate_files('mod_adele', 'intro', null);

        // Return the root element (wrapped into the standard activity structure).
        return $this->prepare_activity_structure($adele);
    }
}

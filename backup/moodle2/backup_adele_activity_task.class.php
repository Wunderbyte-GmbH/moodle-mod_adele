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

 defined('MOODLE_INTERNAL') || die();

 require_once($CFG->dirroot . '/mod/adele/backup/moodle2/backup_adele_stepslib.php');

 /**
  * Provides the backup task for the Adele activity module.
  */
class backup_adele_activity_task extends backup_activity_task {
    /**
     * Defines settings for the backup task.
     */
    protected function define_my_settings() {
        // No specific settings for this activity.
    }

    /**
     * Defines steps for the backup process.
     */
    protected function define_my_steps() {
        $this->add_step(new backup_adele_activity_structure_step('adele_structure', 'adele.xml'));
    }

    /**
     * Defines steps for the backup process.
     */
    public function get_fileareas() {
        return [];
    }

   /**
    * Encodes URLs to the activity instance for Moodle backup.
    *
    * @param string $content HTML content containing URLs to the activity instance.
    * @return string Encoded content with URLs converted.
    */
    public static function encode_content_links($content) {
        return $content; // Keine Links zu encodieren.
    }
}

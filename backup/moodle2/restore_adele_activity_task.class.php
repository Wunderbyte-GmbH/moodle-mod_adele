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

require_once($CFG->dirroot . '/mod/adele/backup/moodle2/restore_adele_stepslib.php');

/**
 * Provides the restore task for the Adele activity module.
 */
class restore_adele_activity_task extends restore_activity_task {
    /**
     * Defines settings for the restore task.
     */
    protected function define_my_settings() {
        // No specific settings for this activity.
    }

    /**
     * Defines steps for the restore process.
     */
    protected function define_my_steps() {
        $this->add_step(new restore_adele_activity_structure_step('adele_structure', 'adele.xml'));
    }

    /**
     * Defines steps for the restore process.
     */
    public function get_fileareas() {
        return null; // Keine Dateien.
    }

    /**
     * Decodes content links during restore.
     *
     * @param string $content The content containing encoded links.
     * @return string The content with links decoded.
     */
    public static function decode_content_links($content) {
        return $content; // No specific decoding needed for this activity.
    }
}

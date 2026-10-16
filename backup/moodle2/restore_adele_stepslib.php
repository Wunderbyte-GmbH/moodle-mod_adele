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
 * Restores the adele module.
 *
 */
class restore_adele_activity_structure_step extends restore_activity_structure_step {
    /**
     * Defines the structure
     *
     * @return array
     *
     */
    protected function define_structure() {
        $paths = [];
        $paths[] = new restore_path_element('adele', '/activity/adele');
        return $this->prepare_activity_structure($paths);
    }

    /**
     * Processes adele.
     *
     * @param mixed $data
     * @return void
     *
     */
    protected function process_adele($data) {
        global $DB;

        $data = (object)$data;
        $data->course = $this->get_courseid();
        $newitemid = $DB->insert_record('adele', $data);
        $this->apply_activity_instance($newitemid);
    }

    /**
     * Defines steps for the backup process.
     */
    protected function after_execute() {
    }

    /**
     * A restored embedding subscribes and enrols like a new one.
     *
     * A restore (from the recycle bin, a backup or an import) fires no
     * course_module_created event, so the observer that subscribes the
     * course's participants when an activity is created never ran: the
     * embedding came back, the learners did not (measured 2026-10-15,
     * recycle bin). The same work is done here, once the whole restore is
     * finished and the course's enrolments exist again. The subscriptions are
     * new - the backup holds no learner path data - and read each learner's
     * progress from the courses (decision of 2026-10-15).
     *
     * @return void
     */
    protected function after_restore() {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/mod/adele/lib.php');

        $adeleid = (int) $this->task->get_activityid();
        $record = $DB->get_record('adele', ['id' => $adeleid], 'id, course, learningpathid');
        if (!$record || !$DB->record_exists('local_adele_learning_paths', ['id' => $record->learningpathid])) {
            // Restored onto a site without that learning path: nothing to subscribe to.
            return;
        }
        $data = (object) [
            'courseid' => (int) $record->course,
            'userid' => (int) $USER->id,
            'other' => ['modulename' => 'adele', 'instanceid' => $adeleid],
        ];
        \mod_adele_observer::saved_module($data);
        adele_queue_host_reconcile((int) $record->learningpathid, (int) $record->course);
    }
}

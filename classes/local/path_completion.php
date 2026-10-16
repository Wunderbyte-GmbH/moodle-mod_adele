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

namespace mod_adele\local;

/**
 * The link between a learner's learning path and the completion of the
 * activities that embed it (completion rule "learning path finished").
 *
 * "Finished" is what the participant list shows as 100 %: every node of the
 * learner's best route is completed (local_adele's getnodeprogress()). An
 * alternative branch the learner did not take does not stand in the way; a
 * completion that is taken back - a manual or master completion removed -
 * takes the activity completion back with it.
 *
 * @package     mod_adele
 * @copyright   2026 Wunderbyte GmbH
 * @copyright   2026 Ralf Erlebach
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class path_completion {
    /** The completion rule's name, as the form and the database call it. */
    public const RULE = 'completionlearningpathfinished';

    /**
     * Whether a learner has finished a learning path.
     *
     * Read from the learner's stored snapshot, so it says what the path view
     * says.
     *
     * @param int $learningpathid The learning path.
     * @param int $userid The learner.
     * @return bool
     */
    public static function is_finished(int $learningpathid, int $userid): bool {
        global $DB;
        if ($learningpathid <= 0 || !class_exists('\\local_adele\\learning_paths')) {
            return false;
        }
        $json = $DB->get_field('local_adele_path_user', 'json', [
            'learning_path_id' => $learningpathid,
            'user_id' => $userid,
            'status' => 'active',
        ]);
        if (!$json) {
            return false;
        }
        $snapshot = json_decode($json);
        if (!is_object($snapshot)) {
            return false;
        }
        $progress = \local_adele\learning_paths::getnodeprogress($snapshot);
        $total = (int) ($progress['route_total'] ?? 0);
        return $total > 0 && (int) ($progress['route_completed'] ?? 0) >= $total;
    }

    /**
     * Re-evaluate the completion of every activity that embeds the path and
     * uses the rule - in both directions - for one learner.
     *
     * @param int $learningpathid The learning path.
     * @param int $userid The learner.
     */
    public static function update_activities(int $learningpathid, int $userid): void {
        global $DB, $CFG;
        if ($learningpathid <= 0 || $userid <= 0) {
            return;
        }
        $activities = $DB->get_records('adele', ['learningpathid' => $learningpathid, self::RULE => 1], '', 'id, course');
        if (!$activities) {
            return;
        }
        require_once($CFG->libdir . '/completionlib.php');
        foreach ($activities as $activity) {
            $cm = get_coursemodule_from_instance('adele', $activity->id, $activity->course);
            if (!$cm || !empty($cm->deletioninprogress)) {
                continue;
            }
            $course = get_course($activity->course);
            $completion = new \completion_info($course);
            if ($completion->is_enabled($cm) != COMPLETION_TRACKING_AUTOMATIC) {
                continue;
            }
            // COMPLETION_UNKNOWN: the state may have changed either way.
            $completion->update_state($cm, COMPLETION_UNKNOWN, $userid);
        }
    }
}

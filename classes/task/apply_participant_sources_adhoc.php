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
 * Background re-application of an activity's participant sources.
 *
 * @package     mod_adele
 * @copyright   2026 Wunderbyte GmbH
 * @copyright   2026 Ralf Erlebach
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_adele\task;

/**
 * Queued for every learning path activity when the site changes its graded
 * roles (local_adele #593).
 *
 * Who takes part in a course - and so becomes a learner of the paths it
 * embeds - follows the site's graded roles. People are subscribed when they
 * are enrolled or given a role; somebody who ALREADY holds a role that only
 * now becomes a graded role has no such event. This task walks the
 * activity's participant sources the way saving the activity does, so those
 * people become learners without anybody touching each course.
 *
 * The other direction - a role that stops being a graded role - needs no
 * task: its holders are off the lists at once, and enrol_adele's nightly
 * sweep removes, after its period of grace, the subscriptions nothing
 * carries any more.
 *
 * Queued rather than run inline: a site can have many activities and a
 * course many members, and saving a settings page must not wait for them.
 * Idempotent.
 *
 * @package     mod_adele
 * @copyright   2026 Ralf Erlebach
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class apply_participant_sources_adhoc extends \core\task\adhoc_task {
    /**
     * Apply the participant sources of the activity in custom data.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;
        $instanceid = (int) ($this->get_custom_data()->instanceid ?? 0);
        $instance = $instanceid ? $DB->get_record('adele', ['id' => $instanceid], 'id, course') : false;
        if (!$instance) {
            // Deleted in the meantime.
            return;
        }
        \mod_adele_observer::saved_module((object) [
            'courseid' => (int) $instance->course,
            'userid' => (int) get_admin()->id,
            'other' => ['modulename' => 'adele', 'instanceid' => (int) $instance->id],
        ]);
    }
}

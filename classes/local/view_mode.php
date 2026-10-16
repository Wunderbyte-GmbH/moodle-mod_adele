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
 * Which view of an embedded learning path somebody gets (#39).
 *
 * The teacher's view - every learner, and the way into a learner's path -
 * used to hang on mod/adele:addinstance, the right to CREATE the activity.
 * It follows what the view is about now:
 *
 *  - local/adele:teacheredit         the teacher's view, with the controls
 *                                    that change a learner's path;
 *  - local/adele:viewparticipants    the teacher's view, read-only - the
 *                                    teacher without editing rights sees
 *                                    everything and changes nothing;
 *  - mod/adele:readinstance          the participant's view.
 *
 * The web services of local_adele check the same capabilities, so the page
 * shows what the server hands out and offers what the server accepts.
 *
 * @package     mod_adele
 * @copyright   2026 Wunderbyte GmbH
 * @copyright   2026 Ralf Erlebach
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class view_mode {
    /**
     * The view for the current user in an activity's context.
     *
     * @param \context $context The module context.
     * @return array ['view' => 'teacher'|'student'|null, 'readonly' => bool]
     */
    public static function for_context(\context $context): array {
        if (has_capability('local/adele:teacheredit', $context)) {
            return ['view' => 'teacher', 'readonly' => false];
        }
        if (has_capability('local/adele:viewparticipants', $context)) {
            return ['view' => 'teacher', 'readonly' => true];
        }
        if (has_capability('mod/adele:readinstance', $context)) {
            return ['view' => 'student', 'readonly' => false];
        }
        return ['view' => null, 'readonly' => false];
    }
}

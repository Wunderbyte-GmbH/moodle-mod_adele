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
 * Plugin capabilities are defined here.
 *
 * @package     mod_adele
 * @author      Jacob Viertel
 * @copyright  2024 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

 defined('MOODLE_INTERNAL') || die();

 $observers = [
    [
        'eventname' => '\core\event\course_module_created',
        'callback' => 'mod_adele_observer::saved_module',
    ],
    [
        'eventname' => '\core\event\course_module_updated',
        'callback' => 'mod_adele_observer::saved_module',
    ],
    [
        'eventname' => '\core\event\user_enrolment_created',
        'callback' => 'mod_adele_observer::user_enrolment_created',
    ],
    [
        // Live counterpart to the enrolment case above: losing membership in
        // a node course must revoke host-course access the same way gaining
        // it granted access (subscription options 2/3).
        'eventname' => '\core\event\user_enrolment_deleted',
        'callback' => 'mod_adele_observer::user_enrolment_deleted',
    ],
    [
        // Taking part in a course is a matter of the role (local_adele #587):
        // a role change is re-evaluated like an enrolment.
        'eventname' => '\core\event\role_assigned',
        'callback' => 'mod_adele_observer::role_changed',
    ],
    [
        'eventname' => '\core\event\role_unassigned',
        'callback' => 'mod_adele_observer::role_changed',
    ],
    [
        // Who takes part follows the site's graded roles (local_adele #593):
        // when that setting changes, the participant sources of every
        // activity are applied again, in the background.
        'eventname' => '\core\event\config_log_created',
        'callback' => 'mod_adele_observer::site_config_changed',
    ],
    [
        // Completion rule "learning path finished". local_adele recomputes
        // and STORES the learner's path in its own observer of this event;
        // the low priority puts this observer after it, so it reads the new
        // state (path_completion_test pins the order).
        'eventname' => '\local_adele\event\user_path_updated',
        'callback' => 'mod_adele_observer::learner_path_updated',
        'priority' => -100,
    ],
 ];

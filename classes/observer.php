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
 * Event observers.
 *
 * @package mod_adele
 * @copyright 2024 Georg Maißer <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\event\base;
use local_adele\enrollment;
use local_adele\learning_paths;

/**
 * Event observer for local_adele.
 */
class mod_adele_observer {

    /**
     * Observer for changes inside the module.
     * We check if the module is a adele mod.
     * We check if something was changed, if possible.
     * We enroll the users that meet the criteria into the course.
     *
     * @param base $data
     * @return base
     */
    public static function saved_module($data) {
        global $DB;
        if ($data->other['modulename'] == 'adele') {
            $adelelp = $DB->get_record(
                'adele',
                ['id' => $data->other['instanceid']],
                'learningpathid, participantslist'
            );
            if ($adelelp->participantslist == '1') {
                // Get all users and subscribe them to learning path.
                $learningpath = learning_paths::get_learning_path_by_id($adelelp->learningpathid);
                $coursecontext = context_course::instance($data->courseid);
                $enrolledusers = get_enrolled_users($coursecontext, '', 0, 'u.id, u.username, u.firstname, u.lastname, u.email');
                $userparams = new stdClass();
                $userparams->relateduserid = $data->userid;
                foreach ($enrolledusers as $user) {
                    $userparams->userid = $user->id;
                    enrollment::subscribe_user_to_learning_path($learningpath, $userparams);
                }
            } else if ($adelelp->participantslist == '2') {
                // Get all users from starting nodes an subscribe.
                $learningpath = learning_paths::get_learning_path_by_id($adelelp->learningpathid);
                $learningpath->json = json_decode($learningpath->json, true);
                foreach ($learningpath->json['tree']['nodes'] as $node) {
                    if (in_array('starting_node', $node['parentCourse'])) {
                        foreach ($node['data']['course_node_id'] as $startingnodeid) {
                            $coursecontext = context_course::instance($startingnodeid);
                            $enrolledusers = get_enrolled_users($coursecontext, '', 0, 'u.id');
                            $userparams = new stdClass();
                            $userparams->userid = $data->userid;
                            foreach ($enrolledusers as $user) {
                                $userparams->relateduserid = $user->id;
                                enrollment::subscribe_user_to_learning_path($learningpath, $userparams);
                            }
                        }
                    }

                }
            }
        }
        return $data;
    }

    /**
     * Observer for changes inside the module.
     * We enrol the user to the learningpath.
     *
     * @param base $data
     * @return base
     */
    public static function user_enrolment_created($data) {
        global $DB;
        $modules = get_course_mods($data->courseid);
        foreach ($modules as $module) {
            if ($module->modname == 'adele' && $module->deletioninprogress == 0) {
                $adelelp = $DB->get_record(
                    'adele',
                    ['id' => $module->instance],
                    'learningpathid, participantslist'
                );
                if ($adelelp->participantslist == '1') {
                    // Subscribe user to learning path.
                    $learningpath = learning_paths::get_learning_path_by_id($adelelp->learningpathid);
                    enrollment::subscribe_user_to_learning_path($learningpath, $data);
                }
            }
        }
        return $data;
    }
}

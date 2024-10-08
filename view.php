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
 * Prints an instance of mod_adele.
 *
 * @package     mod_adele
 * @copyright   2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_adele\local_adele;

require(__DIR__.'/../../config.php');
require_once(__DIR__.'/lib.php');

// Course module id.
$id = optional_param('id', 0, PARAM_INT);

// Activity instance id.
$a = optional_param('a', 0, PARAM_INT);

if ($id) {
    $cm = get_coursemodule_from_id('adele', $id, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    $moduleinstance = $DB->get_record('adele', ['id' => $cm->instance], '*', MUST_EXIST);
} else {
    $moduleinstance = $DB->get_record('adele', ['id' => $a], '*', MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $moduleinstance->course], '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('adele', $moduleinstance->id, $course->id, false, MUST_EXIST);
}

require_login($course, true, $cm);

$modulecontext = context_module::instance($cm->id);

$event = \mod_adele\event\course_module_viewed::create([
    'objectid' => $moduleinstance->id,
    'context' => $modulecontext,
]);
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot('adele', $moduleinstance);
$event->trigger();

$PAGE->set_url('/mod/adele/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($moduleinstance->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($modulecontext);
$learningpath = $DB->get_record(
  'adele',
  [
    'id' => $cm->instance,
    'course' => $cm->course,
  ],
  'id, learningpathid, view, userlist'
);

echo $OUTPUT->header();

// Early bail out conditions.
if (
  isloggedin() &&
  !isguestuser() &&
  $learningpath->view >= 1 &&
  $learningpath->learningpathid
) {
    $alisecompatible = local_adele::get_internalquuiz_id($learningpath->learningpathid, $PAGE->course->id);
    if (has_capability('mod/adele:addinstance', $modulecontext)) {
        if ($alisecompatible['alisecompatible']) {
            echo $OUTPUT->render_from_template('local_adele/initview',
            [
              'userid' => $USER->id,
              'contextid' => $modulecontext->id,
              'quizsetting' => get_config('local_adele', 'quizsettings'),
              'learningpath' => $learningpath->learningpathid,
              'userlist' => $learningpath->userlist,
              'view' => "teacher",
              'wwwroot' => $CFG->wwwroot,
              'version' => $CFG->version,
            ]);
        } else {
            echo <<<EOT
                <div style="background-color: #f8d7da; border: 1px solid #f5c6cb; border-radius: 5px;
                    padding: 15px; margin-bottom: 20px; color: #721c24;">
                    <i class="fas fa-exclamation-circle" style="color: #721c24; margin-right: 10px;"></i>
                    <strong>{$alisecompatible['msg']}</strong>
                </div>
            EOT;
        }
    } else if (has_capability('mod/adele:readinstance', $modulecontext)) {
        if ($alisecompatible['alisecompatible']) {
            echo $OUTPUT->render_from_template('local_adele/initview',
            [
              'userid' => $USER->id,
              'contextid' => $modulecontext->id,
              'quizsetting' => get_config('local_adele', 'quizsettings'),
              'learningpath' => $learningpath->learningpathid,
              'userlist' => $learningpath->userlist,
              'view' => "student",
              'wwwroot' => $CFG->wwwroot,
              'version' => $CFG->version,
            ]);
        } else {
            echo <<<EOT
                <div style="background-color: #f8d7da; border: 1px solid #f5c6cb; border-radius: 5px;
                    padding: 15px; margin-bottom: 20px; color: #721c24;">
                    <i class="fas fa-exclamation-circle" style="color: #721c24; margin-right: 10px;"></i>
                    <strong>{$alisecompatible['msg']}</strong>
                </div>
            EOT;
        }
    }
}

echo $OUTPUT->footer();

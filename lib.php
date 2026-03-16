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
 * Library of interface functions and constants.
 *
 * @package     mod_adele
 * @copyright   2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_adele\local_adele;

/**
 * Return if the plugin supports $feature.
 *
 * @param string $feature Constant representing the feature.
 * @return true | null True if the feature is supported, null otherwise.
 */
function adele_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
        case FEATURE_BACKUP_MOODLE2:
        case FEATURE_COMPLETION_TRACKS_VIEWS:
        case FEATURE_COMPLETION_HAS_RULES:
            return true;
        default:
            return null;
    }
}

/**
 * Saves a new instance of the mod_adele into the database.
 *
 * Given an object containing all the necessary data, (defined by the form
 * in mod_form.php) this function will create a new instance and return the id
 * number of the instance.
 *
 * @param object $moduleinstance An object from the form.
 * @param mod_adele_mod_form $mform The form.
 * @return int The id of the newly inserted record.
 */
function adele_add_instance($moduleinstance, $mform = null) {
    global $DB;

    $moduleinstance->timecreated = time();

    $moduleinstance->participantslist = implode(',', $moduleinstance->participantslist);

    $moduleinstance->completionlearningpathfinished =
        isset($moduleinstance->completionlearningpathfinished) ? $moduleinstance->completionlearningpathfinished : 0;

    $id = $DB->insert_record('adele', $moduleinstance);

    return $id;
}

/**
 * Updates an instance of the mod_adele in the database.
 *
 * Given an object containing all the necessary data (defined in mod_form.php),
 * this function will update an existing instance with new data.
 *
 * @param object $moduleinstance An object from the form in mod_form.php.
 * @param mod_adele_mod_form $mform The form.
 * @return bool True if successful, false otherwise.
 */
function adele_update_instance($moduleinstance, $mform = null) {
    global $DB;

    $moduleinstance->timemodified = time();
    $moduleinstance->id = $moduleinstance->instance;

    $moduleinstance->participantslist = implode(',', $moduleinstance->participantslist);

    $moduleinstance->completionlearningpathfinished =
        isset($moduleinstance->completionlearningpathfinished) ? $moduleinstance->completionlearningpathfinished : 0;

    return $DB->update_record('adele', $moduleinstance);
}

/**
 * Removes an instance of the mod_adele from the database.
 *
 * @param int $id Id of the module instance.
 * @return bool True if successful, false on failure.
 */
function adele_delete_instance($id) {
    global $DB;

    $exists = $DB->get_record('adele', ['id' => $id]);
    if (!$exists) {
        return false;
    }

    $DB->delete_records('adele', ['id' => $id]);

    return true;
}

/**
 * Sets content of mod.
 *
 * @param cm_info $cm The course module information object.
 */
function mod_adele_cm_info_view(cm_info $cm) {
    global $DB, $PAGE, $USER, $OUTPUT, $CFG;
    $learningpathmod = $DB->get_record(
      'adele',
      [
        'id' => $cm->instance,
        'course' => $cm->course,
      ],
      'id, learningpathid, view, userlist'
    );
    if (
          isloggedin() &&
          !isguestuser() &&
          $learningpathmod->view == 1 &&
          $learningpathmod->learningpathid
        ) {
        $alisecompatible = local_adele::get_internalquuiz_id($learningpathmod->learningpathid, $PAGE->course->id);
        $modulecontext = context_module::instance($cm->id);
        if (has_capability('mod/adele:addinstance', $modulecontext)) {
            if ($alisecompatible['alisecompatible']) {
                $html = $OUTPUT->render_from_template('local_adele/initview',
                  [
                    'userid' => $USER->id,
                    'contextid' => $modulecontext->id,
                    'quizsetting' => get_config('local_adele', 'quizsettings'),
                    'learningpath' => $learningpathmod->learningpathid,
                    'userlist' => $learningpathmod->userlist,
                    'view' => "teacher",
                    'wwwroot' => $CFG->wwwroot,
                    'version' => $CFG->version,
                ]);
            } else {
                $html = '<div style="background-color: #f8d7da; border: 1px solid #f5c6cb; border-radius: 5px;
                    padding: 15px; margin-bottom: 20px; color: #721c24;">
                    <i class="fas fa-exclamation-circle" style="color: #721c24; margin-right: 10px;"></i>
                    <strong>' . $alisecompatible['msg'] . '</strong>
                </div>';
            }
            $cm->set_content($html);
        } else if (has_capability('mod/adele:readinstance', $modulecontext)) {
            if ($alisecompatible['alisecompatible']) {
                $html = $OUTPUT->render_from_template('local_adele/initview',
                  [
                    'userid' => $USER->id,
                    'contextid' => $modulecontext->id,
                    'quizsetting' => get_config('local_adele', 'quizsettings'),
                    'learningpath' => $learningpathmod->learningpathid,
                    'userlist' => $learningpathmod->userlist,
                    'view' => "student",
                    'wwwroot' => $CFG->wwwroot,
                    'version' => $CFG->version,
                ]);
            } else {
                $html = '<div style="background-color: #f8d7da; border: 1px solid #f5c6cb; border-radius: 5px;
                    padding: 15px; margin-bottom: 20px; color: #721c24;">
                    <i class="fas fa-exclamation-circle" style="color: #721c24; margin-right: 10px;"></i>
                    <strong>' . $alisecompatible['msg'] . '</strong>
                </div>';
            }
            $cm->set_content($html);
        }
    }
}

/**
 * This callback is used by the core to add any "extra" information to the activity. For example, completion info.
 * @param stdClass $coursemodule
 * @return false|cached_cm_info
 */
function adele_get_coursemodule_info(stdClass $coursemodule) {
    global $DB;
    $table = 'adele';
    $learningpath = $DB->get_record(
        $table,
        ['id' => $coursemodule->instance],
        'id, name, intro,introformat, completionlearningpathfinished'
    );
    if (!$learningpath) {
        return false;
    }

    $result = new cached_cm_info();
    $result->name = $learningpath->name;

    if ($coursemodule->showdescription) {
        $result->content = format_module_intro($table, $learningpath, $coursemodule->id, false);
    }

    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $result->customdata['customcompletionrules']['completionlearningpathfinished'] =
            $learningpath->completionlearningpathfinished;
    }

    return $result;
}

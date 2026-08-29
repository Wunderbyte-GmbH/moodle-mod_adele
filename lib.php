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
 * @copyright   2026 Ralf Erlebach
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

    // The autocomplete-multiple element in mod_form.php submits an array, but
    // some generator/import paths (e.g. tests/backup_restore_test.php) supply
    // a plain string instead; implode() on a string throws a TypeError since
    // PHP 8.1, so both shapes are handled explicitly here.
    $moduleinstance->participantslist = is_array($moduleinstance->participantslist)
        ? implode(',', $moduleinstance->participantslist)
        : (string) $moduleinstance->participantslist;

    $moduleinstance->completionlearningpathfinished =
        isset($moduleinstance->completionlearningpathfinished) ? $moduleinstance->completionlearningpathfinished : 0;

    $id = $DB->insert_record('adele', $moduleinstance);

    adele_queue_host_reconcile(
        (int) $moduleinstance->learningpathid,
        (int) $moduleinstance->course
    );

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

    // The PREVIOUS state, read before the update overwrites it. Without it
    // an activity moved from learning path A to B leaves A behind entirely:
    // nothing afterwards knows that A was ever embedded here, so nobody
    // revokes the access A granted (issue #8).
    $previous = $DB->get_record('adele', ['id' => $moduleinstance->id], 'id, course, learningpathid');

    // Same defensive fix as adele_add_instance() above.
    $moduleinstance->participantslist = is_array($moduleinstance->participantslist)
        ? implode(',', $moduleinstance->participantslist)
        : (string) $moduleinstance->participantslist;

    $moduleinstance->completionlearningpathfinished =
        isset($moduleinstance->completionlearningpathfinished) ? $moduleinstance->completionlearningpathfinished : 0;

    $result = $DB->update_record('adele', $moduleinstance);

    // The course property is not guaranteed to be present on every code
    // path that reaches an update (some callers build a partial object), so
    // fall back to the stored value rather than reconcile a zero courseid.
    $courseid = $moduleinstance->course ?? $DB->get_field('adele', 'course', ['id' => $moduleinstance->id]);
    adele_queue_host_reconcile((int) $moduleinstance->learningpathid, (int) $courseid);

    // Reconcile the learning path this activity no longer embeds, so the
    // access it used to justify is withdrawn now rather than at the next
    // nightly sweep. Queued for the OLD course id too, in case the activity
    // was moved between courses.
    if ($previous && (int) $previous->learningpathid !== (int) $moduleinstance->learningpathid) {
        adele_queue_host_reconcile((int) $previous->learningpathid, (int) $previous->course);
    }

    return $result;
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

    // The host-course enrolments this embedding justified are now
    // unjustified. Nothing else would ever notice: the orphan cleanup keys
    // on deleted LEARNING PATHS, and the learning path is still very much
    // alive here - it just is not embedded in this course any more. Without
    // this call the affected users keep an enrolment nobody can explain and
    // nobody can remove (issue #7).
    adele_queue_host_reconcile((int) $exists->learningpathid, (int) $exists->course);

    return true;
}

/**
 * Ask enrol_adele to re-derive host-course access for one embedding.
 *
 * Queued rather than run inline: a popular host course can hold thousands of
 * enrolments, and saving an activity must not block on them. The task is
 * idempotent, so a duplicate queue entry is harmless.
 *
 * Two different absences, deliberately treated differently:
 *
 * - enrol_adele is not there at all. Nothing maintains ADELE enrolments, and
 *   there is deliberately no enrol_manual fallback, so this warns through
 *   local_adele's standing message rather than failing silently.
 * - enrol_adele is installed but predates this task. That is a partial
 *   upgrade, not a broken installation: its nightly reconcile still corrects
 *   host access, and this call would only have made it immediate. Warning
 *   here would fire on every activity save during the upgrade window for a
 *   condition that resolves itself, so it stays quiet.
 *
 * @param int $learningpathid The learning path the activity embeds.
 * @param int $hostcourseid The course the activity lives in.
 * @return void
 */
function adele_queue_host_reconcile(int $learningpathid, int $hostcourseid): void {
    if (!$learningpathid || !$hostcourseid) {
        return;
    }
    if (!class_exists('\\enrol_adele\\local\\reconciler')) {
        \local_adele\enrol_state::warn_enrol_adele_missing();
        return;
    }
    if (!class_exists('\\enrol_adele\\task\\reconcile_host_embedding_adhoc')) {
        return;
    }
    $task = new \enrol_adele\task\reconcile_host_embedding_adhoc();
    $task->set_custom_data([
        'learningpathid' => $learningpathid,
        'hostcourseid' => $hostcourseid,
    ]);
    \core\task\manager::queue_adhoc_task($task, true);
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
        $alisecompatible = local_adele::get_internal_quiz_id($learningpathmod->learningpathid, $PAGE->course->id);
        $modulecontext = context_module::instance($cm->id);
        if (has_capability('mod/adele:addinstance', $modulecontext)) {
            if ($alisecompatible['alisecompatible']) {
                $html = $OUTPUT->render_from_template(
                    'local_adele/initview',
                    [
                    'userid' => $USER->id,
                    'contextid' => $modulecontext->id,
                    'quizsetting' => get_config('local_adele', 'quizsettings'),
                    'learningpath' => $learningpathmod->learningpathid,
                    'userlist' => $learningpathmod->userlist,
                    'view' => "teacher",
                    'wwwroot' => $CFG->wwwroot,
                    'version' => $CFG->version,
                    ]
                );
            } else {
                $html = '<div style="background-color: #f8d7da; border: 1px solid #f5c6cb; border-radius: 5px;
                    padding: 15px; margin-bottom: 20px; color: #721c24;">
                    <i class="fas fa-exclamation-circle" style="color: #721c24; margin-right: 10px;"></i>
                    <strong>' . s($alisecompatible['msg']) . '</strong>
                </div>';
            }
            $cm->set_content($html);
        } else if (has_capability('mod/adele:readinstance', $modulecontext)) {
            if ($alisecompatible['alisecompatible']) {
                $html = $OUTPUT->render_from_template(
                    'local_adele/initview',
                    [
                    'userid' => $USER->id,
                    'contextid' => $modulecontext->id,
                    'quizsetting' => get_config('local_adele', 'quizsettings'),
                    'learningpath' => $learningpathmod->learningpathid,
                    'userlist' => $learningpathmod->userlist,
                    'view' => "student",
                    'wwwroot' => $CFG->wwwroot,
                    'version' => $CFG->version,
                    ]
                );
            } else {
                $html = '<div style="background-color: #f8d7da; border: 1px solid #f5c6cb; border-radius: 5px;
                    padding: 15px; margin-bottom: 20px; color: #721c24;">
                    <i class="fas fa-exclamation-circle" style="color: #721c24; margin-right: 10px;"></i>
                    <strong>' . s($alisecompatible['msg']) . '</strong>
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

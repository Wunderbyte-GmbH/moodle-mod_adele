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
     * Check whether the local_adele classes this observer relies on exist.
     *
     * Ported from the ralferlebach-fix-enrolment-issue branch: keeps the module
     * from fataling when local_adele is missing or being upgraded.
     *
     * @return bool
     */
    private static function is_local_adele_available(): bool {
        return class_exists('local_adele\\learning_paths')
            && class_exists('local_adele\\enrollment');
    }

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
            if (!self::is_local_adele_available()) {
                return $data;
            }
            $adelelp = $DB->get_record(
                'adele',
                ['id' => $data->other['instanceid']],
                'learningpathid, participantslist, hostenrolmentmode'
            );
            if (!$adelelp) {
                return $data;
            }
            $adelelp->participantslist = explode(',', $adelelp->participantslist);
            foreach ($adelelp->participantslist as $participantslist) {
                if ($participantslist == '1') {
                    self::enroll_all_participants($adelelp, $data);
                } else if ($participantslist == '2') {
                    self::enroll_starting_nodes_participants($adelelp, $data);
                } else if ($participantslist == '3') {
                    self::enroll_any_nodes_participants($adelelp, $data);
                }
            }
        }
        return $data;
    }

    /**
     * Observer for changes inside the module.
     * We enrol the user to the learningpath.
     *
     * Handles subscription option 1 directly (the changed course itself hosts
     * the activity), then hands off to sync_host_access_for_node_enrolment()
     * for options 2/3, where the changed course is a NODE course of some
     * OTHER embedding rather than its host.
     *
     * @param base $data
     * @return base
     */
    public static function user_enrolment_created($data) {
        global $DB;
        if (!self::is_local_adele_available()) {
            return $data;
        }
        $modules = get_course_mods($data->courseid);
        if ($modules) {
            foreach ($modules as $module) {
                if ($module->modname == 'adele' && $module->deletioninprogress == 0) {
                    $adelelp = $DB->get_record(
                        'adele',
                        ['id' => $module->instance],
                        'learningpathid, participantslist'
                    );
                    if (!$adelelp) {
                        continue;
                    }
                    // The stored value is a comma list ('1', '2', '1,2', ...): explode
                    // before comparing. The former raw comparison ($participantslist
                    // == '1') silently skipped the subscription whenever more than
                    // one option was selected (fix A-14).
                    $options = explode(',', (string) $adelelp->participantslist);
                    if (in_array('1', $options)) {
                        // Subscribe user to learning path.
                        $learningpath = learning_paths::get_learning_path_by_id($adelelp->learningpathid);
                        if ($learningpath) {
                            enrollment::subscribe_user_to_learning_path($learningpath, $data);
                        }
                    }
                }
            }
        }
        self::sync_host_access_for_node_enrolment($data);
        return $data;
    }

    /**
     * Observer for user_enrolment_deleted.
     *
     * Options 2/3 grant host-course access as a CONSEQUENCE of node-course
     * membership; losing that membership must be reflected the same way
     * losing it is granted — live, not only re-evaluated the next time the
     * activity is saved. Option 1 is intentionally NOT handled here: a host
     * course enrolment lost through option 1 is the trigger enrol_adele's own
     * observer (requirement A-4) already reacts to on the target-course side;
     * this observer only concerns the host-course consequence of options 2/3.
     *
     * @param base $data
     * @return base
     */
    public static function user_enrolment_deleted($data) {
        if (!self::is_local_adele_available()) {
            return $data;
        }
        self::sync_host_access_for_node_enrolment($data);
        return $data;
    }

    /**
     * Recompute and apply host-course access for every option-2/3 embedding
     * that the changed course is a qualifying node course of.
     *
     * Deliberately recomputes entitlement FRESH from the current enrolment
     * state rather than trusting the single event that triggered the call: a
     * node course can be shared by several nodes, and a user can hold several
     * concurrent node-course enrolments, so only a fresh read is race-safe.
     * Warns clearly via local_adele\enrol_state::warn_enrol_adele_missing()
     * instead of silently no-op'ing when enrol_adele is not installed
     * (decision G-Q1a, Session 003, revokes L-Q-08) — since the E-16 fix,
     * this is also the only host-course trigger for the sweep methods
     * (enroll_starting_nodes_participants()/enroll_any_nodes_participants()
     * now route through here too via a synthetic event, so the earlier note
     * about them falling back to enrol_manual no longer applies).
     *
     * Requirement mod_adele #23: several embeddings can target the SAME
     * (learning path, host course) pair — one enrol_adele host instance is
     * shared between them (its identity does not include the mod_adele
     * activity id). Every embedding's (entitled, mode) is therefore computed
     * FIRST and grouped by that pair; only ONE reconcile_host_user() call is
     * made per group, on the aggregated result — never one call per
     * embedding overwriting whatever the previous one decided. The
     * aggregation rule is "most generous option wins": entitled is the union
     * across the group (any embedding granting access is enough), and the
     * visibility mode is the most permissive one among the embeddings that
     * actually granted it (visible > hidden > none) — consistent with the
     * pre-existing target-course rule that a shared course stays accessible
     * as long as any node still grants it (decision F-1/A-6).
     *
     * @param base $data The user_enrolment_created/deleted event data.
     * @return void
     */
    private static function sync_host_access_for_node_enrolment($data): void {
        global $DB;
        if (!class_exists('\enrol_adele\local\reconciler')) {
            \local_adele\enrol_state::warn_enrol_adele_missing();
            return;
        }
        $userid = (int) $data->relateduserid;
        $courseid = (int) $data->courseid;
        if (!$userid || !$courseid) {
            return;
        }

        $embeddings = $DB->get_records(
            'adele',
            null,
            '',
            'id, course, learningpathid, participantslist, hostenrolmentmode'
        );

        // Group by (learningpathid, hostcourseid) — the granularity a single
        // enrol_adele host instance is actually scoped to.
        $groups = [];
        foreach ($embeddings as $embedding) {
            $options = array_map('trim', explode(',', (string) $embedding->participantslist));
            if (!in_array('2', $options) && !in_array('3', $options)) {
                continue;
            }
            $learningpath = learning_paths::get_learning_path_by_id($embedding->learningpathid);
            if (!$learningpath) {
                continue;
            }
            $json = is_string($learningpath->json) ? json_decode($learningpath->json, true) : $learningpath->json;
            $touchesthislp = false;
            foreach (($json['tree']['nodes'] ?? []) as $node) {
                $nodecourseids = array_map('intval', $node['data']['course_node_id'] ?? []);
                if (in_array($courseid, $nodecourseids)) {
                    $touchesthislp = true;
                    break;
                }
            }
            if (!$touchesthislp) {
                // The changed course has nothing to do with this embedding's
                // learning path; skip the more expensive per-option check.
                continue;
            }

            $entitled = false;
            if (in_array('2', $options) && self::is_user_entitled_to_host_via_option($learningpath, $userid, '2')) {
                $entitled = true;
            }
            if (
                !$entitled && in_array('3', $options)
                && self::is_user_entitled_to_host_via_option($learningpath, $userid, '3')
            ) {
                $entitled = true;
            }

            if ($entitled) {
                // Mirror the one-time sweep: make sure the learning-path
                // subscription itself exists too, not just the host enrolment.
                $userparams = new stdClass();
                $userparams->userid = $data->userid ?? $userid;
                $userparams->relateduserid = $userid;
                enrollment::subscribe_user_to_learning_path($learningpath, $userparams);
            }

            $groupkey = $embedding->learningpathid . ':' . $embedding->course;
            if (!isset($groups[$groupkey])) {
                $groups[$groupkey] = (object) [
                    'learningpathid' => (int) $embedding->learningpathid,
                    'hostcourseid' => (int) $embedding->course,
                    'entitled' => false,
                    'moderank' => -1,
                    'mode' => \enrol_adele\local\reconciler::MODE_VISIBLE,
                ];
            }
            if ($entitled) {
                $groups[$groupkey]->entitled = true;
                $mode = (string) ($embedding->hostenrolmentmode ?: \enrol_adele\local\reconciler::MODE_VISIBLE);
                $rank = self::host_mode_rank($mode);
                // Only an embedding that ACTUALLY grants access gets a say in
                // how visible that access is — one that isn't entitled at all
                // must not be able to drag a more generous sibling down.
                if ($rank > $groups[$groupkey]->moderank) {
                    $groups[$groupkey]->moderank = $rank;
                    $groups[$groupkey]->mode = $mode;
                }
            }
        }

        foreach ($groups as $group) {
            \enrol_adele\local\reconciler::reconcile_host_user(
                $group->learningpathid,
                $group->hostcourseid,
                $userid,
                $group->entitled,
                $group->mode
            );
        }
    }

    /**
     * Generosity ranking for host-course visibility modes, highest first:
     * visible > hidden > none. Used to resolve competing embeddings of the
     * same learning path in the same host course (mod_adele #23) — the most
     * permissive mode among the embeddings that actually grant access wins.
     *
     * @param string $mode One of enrol_adele\local\reconciler::MODE_*.
     * @return int Higher is more permissive.
     */
    private static function host_mode_rank(string $mode): int {
        switch ($mode) {
            case \enrol_adele\local\reconciler::MODE_VISIBLE:
                return 2;
            case \enrol_adele\local\reconciler::MODE_HIDDEN:
                return 1;
            default:
                return 0;
        }
    }

    /**
     * Whether a user currently holds ANY enrolment (any method, suspended
     * counts — consistent with decision F-4/A-8 elsewhere in this project) in
     * a node course qualifying under the given subscription option.
     *
     * @param object $learningpath The learning path (json may be string or array).
     * @param int $userid The user id.
     * @param string $option '2' (starting node) or '3' (any node).
     * @return bool
     */
    /**
     * Whether the user is entitled to host-course access via the given option.
     *
     * Fix G.4/G.11 (Session 003): now excludes enrol_adele's own enrolments
     * (matching the revocation-side check in
     * enrol_adele\observer::has_foreign_enrolment()/is_user_carried() —
     * "otherwise access would keep itself alive circularly") and checks
     * timestart/timeend/enrol-instance-status, so a grant and its later
     * revocation are decided by the same definition of "carries the user".
     *
     * @param object $learningpath The learning path record.
     * @param int $userid The user id.
     * @param string $option '2' (starting node) or '3' (any node).
     * @return bool
     */
    private static function is_user_entitled_to_host_via_option($learningpath, int $userid, string $option): bool {
        global $DB;
        $json = is_string($learningpath->json) ? json_decode($learningpath->json, true) : $learningpath->json;
        $courseids = [];
        foreach (($json['tree']['nodes'] ?? []) as $node) {
            if ($option === '2' && !in_array('starting_node', $node['parentCourse'] ?? [])) {
                continue;
            }
            $nodecourseids = $node['data']['course_node_id'] ?? [];
            if (is_array($nodecourseids)) {
                $courseids = array_merge($courseids, array_map('intval', $nodecourseids));
            }
        }
        $courseids = array_unique($courseids);
        if (!$courseids) {
            return false;
        }
        [$insql, $inparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $now = time();
        $sql = "SELECT 1
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE ue.userid = :userid
                       AND e.enrol <> 'adele'
                       AND e.status = :enabled
                       AND (ue.timestart = 0 OR ue.timestart <= :now1)
                       AND (ue.timeend = 0 OR ue.timeend > :now2)
                       AND e.courseid {$insql}";
        return $DB->record_exists_sql($sql, [
            'userid' => $userid,
            'enabled' => ENROL_INSTANCE_ENABLED,
            'now1' => $now,
            'now2' => $now,
        ] + $inparams);
    }

    /**
     * Enroll all participants inside the course.
     *
     * @param object $adelelp
     * @param base $data
     * @param bool $update
     */
    public static function enroll_all_participants($adelelp, $data, $update = false) {
        $learningpath = learning_paths::get_learning_path_by_id($adelelp->learningpathid);
        if (!$learningpath) {
            return;
        }
        $coursecontext = context_course::instance($data->courseid);
        $enrolledusers = get_enrolled_users($coursecontext, '', 0, 'u.id, u.username, u.firstname, u.lastname, u.email');
        $userparams = new stdClass();
        $userparams->userid = $data->userid;
        foreach ($enrolledusers as $user) {
            $userparams->relateduserid = $user->id;
            enrollment::subscribe_user_to_learning_path($learningpath, $userparams);
        }
    }

    /**
     * Enroll all participants inside the starting nodes.
     *
     * Requirement E-16: routes each swept user through
     * sync_host_access_for_node_enrolment() — the SAME aggregation the live
     * observer uses (mod_adele #23) — via a synthetic event-like object,
     * instead of calling subscribe_user_course() directly with only this
     * activity's own mode. Previously, saving a narrower sibling embedding
     * (same learning path, same host course) after a more generous one could
     * transiently downgrade access the sweep for THAT activity had no way to
     * know about; reusing the live aggregation logic here closes that gap
     * without duplicating it. This also folds in the learning-path
     * subscription step (sync_host_access_for_node_enrolment() already does
     * that when entitled), so the separate subscribe_user_to_learning_path()
     * call below is no longer needed for the swept users.
     *
     * @param object $adelelp
     * @param base $data
     * @param bool $update
     */
    public static function enroll_starting_nodes_participants($adelelp, $data, $update = false) {
        $learningpath = learning_paths::get_learning_path_by_id($adelelp->learningpathid);
        if (!$learningpath) {
            return;
        }
        $learningpath->json = json_decode($learningpath->json, true);
        foreach (($learningpath->json['tree']['nodes'] ?? []) as $node) {
            if (in_array('starting_node', $node['parentCourse'])) {
                foreach ($node['data']['course_node_id'] as $startingnodeid) {
                    $coursecontext = context_course::instance($startingnodeid);
                    $enrolledusers = get_enrolled_users($coursecontext, '', 0, 'u.id');
                    foreach ($enrolledusers as $user) {
                        self::sync_host_access_for_node_enrolment((object) [
                            'relateduserid' => $user->id,
                            'courseid' => (int) $startingnodeid,
                            'userid' => $data->userid,
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Enroll all participants inside any node of the learning path.
     *
     * Subscription option 3, ported from the ralferlebach-fix-enrolment-issue
     * branch: everyone enrolled in any node course of the learning path is
     * enrolled into the host course and subscribed to the learning path. Users
     * are deduplicated across node courses. Requirement E-16: same
     * aggregation-reuse rationale as enroll_starting_nodes_participants()
     * above.
     *
     * @param object $adelelp
     * @param base $data
     * @param bool $update
     */
    public static function enroll_any_nodes_participants($adelelp, $data, $update = false) {
        $learningpath = learning_paths::get_learning_path_by_id($adelelp->learningpathid);
        if (!$learningpath) {
            return;
        }
        $learningpath->json = json_decode($learningpath->json, true);
        $seen = [];
        foreach (($learningpath->json['tree']['nodes'] ?? []) as $node) {
            $courseids = $node['data']['course_node_id'] ?? [];
            if (!is_array($courseids)) {
                continue;
            }
            foreach ($courseids as $courseid) {
                $coursecontext = context_course::instance($courseid);
                $enrolledusers = get_enrolled_users($coursecontext, '', 0, 'u.id');
                foreach ($enrolledusers as $user) {
                    if (isset($seen[$user->id])) {
                        continue;
                    }
                    $seen[$user->id] = true;
                    self::sync_host_access_for_node_enrolment((object) [
                        'relateduserid' => $user->id,
                        'courseid' => (int) $courseid,
                        'userid' => $data->userid,
                    ]);
                }
            }
        }
    }

    /**
     * Enrol a single user into the host course, without the multi-embedding
     * aggregation sync_host_access_for_node_enrolment() applies.
     *
     * Requirement following ticket #486 (Session 001 Teil 5): for options 2/3
     * the host-course enrolment is a CONSEQUENCE of node-course membership and
     * must be revocable the same way it was granted, so it goes through
     * enrol_adele — the same instance reconcile_host_user() manages from the
     * live event path in sync_host_access_for_node_enrolment().
     * $mode (requirement mod_adele #22) lets a teacher scale that access back
     * (visible/hidden/none) instead of it always being an active enrolment.
     * Warns clearly via local_adele\enrol_state::warn_enrol_adele_missing()
     * instead of falling back to enrol_manual when enrol_adele is not
     * installed (decision G-Q1a, Session 003, revokes L-Q-08) — no host-course
     * enrolment happens at all in that case, since enrol_manual has no concept
     * of a suspended-but-visible or skipped enrolment matching $mode anyway.
     * $learningpathid is required to take the enrol_adele path.
     *
     * As of the E-16 fix, neither of this class's own sweep methods call this
     * directly any more — they route through sync_host_access_for_node_enrolment()
     * instead, so that a shared host-course instance always reflects every
     * embedding's aggregate decision rather than whichever one happened to
     * run last. Kept as public API for callers that genuinely want to
     * reconcile a single, already-known embedding without that aggregation
     * (e.g. a possible future admin action targeting one specific embedding).
     *
     * @param base $data
     * @param object $user
     * @param int|null $learningpathid Required to enrol via enrol_adele.
     * @param string $mode One of enrol_adele\local\reconciler::MODE_* (defaults to visible).
     */
    public static function subscribe_user_course($data, $user, $learningpathid = null, $mode = 'visible') {
        if ($learningpathid !== null && class_exists('\enrol_adele\local\reconciler')) {
            \enrol_adele\local\reconciler::reconcile_host_user(
                (int) $learningpathid,
                (int) $data->courseid,
                (int) $user->id,
                true,
                $mode
            );
            return;
        }
        \local_adele\enrol_state::warn_enrol_adele_missing();
    }
}

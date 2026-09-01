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
 * Host-course access policy: the single source of truth for subscription
 * options 2 and 3.
 *
 * @package     mod_adele
 * @copyright   2026 Wunderbyte GmbH
 * @copyright   2026 Ralf Erlebach
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_adele\local;

use local_adele\learning_paths;

/**
 * Host-course access policy: the single source of truth for subscription
 * options 2 and 3.
 *
 * The semantics of participantslist ('1,2,3') and hostenrolmentmode
 * ('visible'/'hidden'/'none') belong to this plugin, so the derivation
 * "is user X entitled to host course Y of learning path Z, and in which
 * mode?" lives here and nowhere else. Two callers use it:
 *
 * - mod_adele's own event observer, live on every enrolment change;
 * - enrol_adele's nightly reconcile, indirectly through
 *   local_adele\enrol_state, which may depend on this plugin.
 *
 * enrol_adele therefore never reads the {adele} table and never knows the
 * participantslist format, while the event path and the sweep can no longer
 * disagree about who is entitled.
 *
 * The {adele} table is the only source. An earlier design mirrored part of
 * this information into a local_adele index table so that no plugin outside
 * mod_adele had to know the {adele} schema; that mirror could not carry
 * hostenrolmentmode, which the host-course sweep needs, and a mirror nothing
 * reads is a mirror that silently goes stale. It has been dropped again.
 *
 * @package     mod_adele
 * @copyright   2026 Wunderbyte GmbH
 * @copyright   2026 Ralf Erlebach
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class host_policy {
    /** @var string Host access is granted as an active enrolment. */
    const MODE_VISIBLE = 'visible';

    /** @var string An enrolment record exists but stays suspended. */
    const MODE_HIDDEN = 'hidden';

    /** @var string This embedding never grants host-course access. */
    const MODE_NONE = 'none';

    /**
     * Every embedding of a learning path, read from the authoritative table.
     *
     * @param int $learningpathid The learning path id.
     * @return array List of ['adeleid', 'courseid', 'option1', 'option2',
     *     'option3', 'mode'] — options as bool, mode as one of MODE_*.
     */
    public static function get_embeddings(int $learningpathid): array {
        global $DB;

        $rows = $DB->get_records(
            'adele',
            ['learningpathid' => $learningpathid],
            'id ASC',
            'id, course, participantslist, hostenrolmentmode'
        );
        return self::normalise_embeddings($rows);
    }

    /**
     * Every embedding living in one host course.
     *
     * @param int $courseid The host course id.
     * @return array Same shape as {@see get_embeddings()}, plus 'learningpathid'.
     */
    public static function get_embeddings_in_course(int $courseid): array {
        global $DB;

        $rows = $DB->get_records(
            'adele',
            ['course' => $courseid],
            'id ASC',
            'id, course, learningpathid, participantslist, hostenrolmentmode'
        );
        return self::normalise_embeddings($rows);
    }

    /**
     * Turn raw {adele} rows into the normalised shape callers work with.
     *
     * @param array $rows Records from the {adele} table.
     * @return array Normalised embeddings.
     */
    private static function normalise_embeddings(array $rows): array {
        $result = [];
        foreach ($rows as $row) {
            $options = array_map('trim', explode(',', (string) $row->participantslist));
            $result[] = [
                'adeleid' => (int) $row->id,
                'courseid' => (int) $row->course,
                'learningpathid' => isset($row->learningpathid) ? (int) $row->learningpathid : 0,
                'option1' => in_array('1', $options, true),
                'option2' => in_array('2', $options, true),
                'option3' => in_array('3', $options, true),
                'mode' => self::normalise_mode($row->hostenrolmentmode ?? ''),
            ];
        }
        return $result;
    }

    /**
     * Map a stored hostenrolmentmode onto a known mode, defaulting to visible.
     *
     * An empty or unknown value must not silently become "none": that would
     * revoke access on a typo or on a row written before the field existed.
     *
     * @param string $mode The stored value.
     * @return string One of MODE_VISIBLE, MODE_HIDDEN, MODE_NONE.
     */
    private static function normalise_mode(string $mode): string {
        $known = [self::MODE_VISIBLE, self::MODE_HIDDEN, self::MODE_NONE];
        return in_array($mode, $known, true) ? $mode : self::MODE_VISIBLE;
    }

    /**
     * Which learning paths are embedded in the given course.
     *
     * @param int $courseid The host course id.
     * @return int[] Distinct learning path ids.
     */
    public static function get_learningpaths_embedded_in_course(int $courseid): array {
        global $DB;

        $ids = $DB->get_fieldset_select(
            'adele',
            'DISTINCT learningpathid',
            'course = :courseid',
            ['courseid' => $courseid]
        );
        return array_map('intval', $ids);
    }

    /**
     * Every learning path that is embedded anywhere with option 2 or 3.
     *
     * The entry point for a full sweep: only these learning paths can have
     * host-course enrolments to reconcile at all.
     *
     * @return int[] Distinct learning path ids.
     */
    public static function get_learningpaths_with_host_embeddings(): array {
        global $DB;

        // Every embedded learning path, deliberately without filtering on the
        // subscription options.
        //
        // An earlier version narrowed this with LIKE '%2%' OR LIKE '%3%' over
        // the participantslist column. That was a premature optimisation with
        // only one possible failure mode — a false negative, in which a
        // learning path silently drops out of the sweep and its users are
        // never reconciled. It was also the only string comparison in the
        // whole chain from a stored setting to a granted enrolment, and the
        // one step whose behaviour depends on collation and driver.
        //
        // The cost of dropping it is that paths embedded with option 1 only
        // are visited too; get_entitlement() then returns "not entitled" for
        // them, which is correct and cheap. Correctness over a filter.
        $ids = $DB->get_fieldset_sql("SELECT DISTINCT learningpathid FROM {adele}");
        return array_map('intval', $ids);
    }

    /**
     * Whether the user is entitled to host-course access via the given option.
     *
     * Holds ANY enrolment (any method, suspended counts) in a node course
     * qualifying under the option. Excludes enrol_adele's own enrolments —
     * otherwise access would keep itself alive circularly — and checks
     * timestart/timeend/enrol-instance-status, so a grant and its later
     * revocation are decided by the same definition of "carries the user".
     *
     * @param object $learningpath The learning path record.
     * @param int $userid The user id.
     * @param string $option '2' (starting node) or '3' (any node).
     * @return bool
     */
    public static function is_user_entitled_via_option($learningpath, int $userid, string $option): bool {
        $courseids = self::get_node_courseids($learningpath, $option);
        if (!$courseids) {
            return false;
        }
        return self::has_foreign_enrolment($userid, $courseids);
    }

    /**
     * The node course ids qualifying under one subscription option.
     *
     * @param object $learningpath The learning path record.
     * @param string $option '2' (starting nodes only) or '3' (all nodes).
     * @return int[] Course ids, deduplicated.
     */
    public static function get_node_courseids($learningpath, string $option): array {
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
        return array_values(array_unique($courseids));
    }

    /**
     * Whether the user holds any non-ADELE enrolment in one of the courses.
     *
     * @param int $userid The user id.
     * @param int[] $courseids Course ids to check.
     * @return bool
     */
    private static function has_foreign_enrolment(int $userid, array $courseids): bool {
        global $DB;

        if (!$courseids) {
            return false;
        }
        [$insql, $inparams] = $DB->get_in_or_equal(array_unique($courseids), SQL_PARAMS_NAMED);
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
     * The aggregated host-course entitlement of one user for one
     * (learning path, host course) pair.
     *
     * Several mod_adele activities can embed the SAME learning path in the
     * SAME host course; enrol_adele keeps one shared instance for that pair,
     * because its identity does not include the activity id. The decision is
     * therefore aggregated across every embedding of the pair by the rule
     * "most generous option wins": entitled is the union (any embedding
     * granting access is enough), and the mode is the most permissive one
     * among the embeddings that ACTUALLY granted it — an embedding that is
     * not entitled at all must not drag a more generous sibling down.
     *
     * Returns not entitled when no embedding of that pair exists any more
     * (the activity was deleted), which is what lets a sweep revoke access
     * that has lost its justification.
     *
     * @param int $learningpathid The learning path id.
     * @param int $hostcourseid The host course id.
     * @param int $userid The user id.
     * @return array [bool $entitled, string $mode]
     */
    public static function get_entitlement(int $learningpathid, int $hostcourseid, int $userid): array {
        $learningpath = learning_paths::get_learning_path_by_id($learningpathid);
        if (!$learningpath) {
            return [false, self::MODE_VISIBLE];
        }

        $entitled = false;
        $bestrank = -1;
        $mode = self::MODE_VISIBLE;

        foreach (self::get_embeddings($learningpathid) as $embedding) {
            if ((int) $embedding['courseid'] !== $hostcourseid) {
                continue;
            }
            if (!$embedding['option2'] && !$embedding['option3']) {
                continue;
            }
            $thisone = ($embedding['option2'] && self::is_user_entitled_via_option($learningpath, $userid, '2'))
                || ($embedding['option3'] && self::is_user_entitled_via_option($learningpath, $userid, '3'));
            if (!$thisone) {
                continue;
            }
            $entitled = true;
            $rank = self::mode_rank($embedding['mode']);
            if ($rank > $bestrank) {
                $bestrank = $rank;
                $mode = $embedding['mode'];
            }
        }

        return [$entitled, $mode];
    }

    /**
     * Every user an embedding could possibly entitle, whether or not ADELE
     * knows about them yet.
     *
     * Closes an asymmetry in the sweep. Its other two candidate populations
     * are users with an active subscription and users who already hold an
     * ADELE host enrolment — both of which describe someone ADELE has already
     * seen. A user enrolled in a node course has neither: subscriptions are
     * created only on enrolment into the HOST course (deliberately, so that a
     * node course does not drag users into unrelated learning paths), and the
     * host enrolment is what we are trying to decide about.
     *
     * The consequence, before this method existed: WIDENING a subscription
     * option granted access only through mod_adele's own save-time sweep. If
     * that never ran — a lost event, a direct database change, a restore —
     * nothing ever granted it, and the nightly reconcile could not heal it
     * either. Revocation was self-healing, granting was not.
     *
     * Only non-ADELE enrolments count, and only currently valid ones: the
     * same definition of "carries the user" that {@see is_user_entitled_via_option()}
     * applies, so a grant and its later revocation cannot disagree.
     *
     * @param int $learningpathid The learning path id.
     * @param int $hostcourseid The host course id.
     * @return int[] Candidate user ids, deduplicated.
     */
    public static function get_candidate_userids(int $learningpathid, int $hostcourseid): array {
        global $DB;

        $learningpath = learning_paths::get_learning_path_by_id($learningpathid);
        if (!$learningpath) {
            return [];
        }

        $courseids = [];
        foreach (self::get_embeddings($learningpathid) as $embedding) {
            if ((int) $embedding['courseid'] !== $hostcourseid) {
                continue;
            }
            if ($embedding['option1']) {
                $courseids[] = $hostcourseid;
            }
            if ($embedding['option2']) {
                $courseids = array_merge($courseids, self::get_node_courseids($learningpath, '2'));
            }
            if ($embedding['option3']) {
                $courseids = array_merge($courseids, self::get_node_courseids($learningpath, '3'));
            }
        }
        $courseids = array_values(array_unique(array_map('intval', $courseids)));
        if (!$courseids) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $now = time();
        $userids = $DB->get_fieldset_sql(
            "SELECT DISTINCT ue.userid
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.enrol <> 'adele'
                    AND e.status = :enabled
                    AND (ue.timestart = 0 OR ue.timestart <= :now1)
                    AND (ue.timeend = 0 OR ue.timeend > :now2)
                    AND e.courseid {$insql}",
            [
                'enabled' => ENROL_INSTANCE_ENABLED,
                'now1' => $now,
                'now2' => $now,
            ] + $inparams
        );
        return array_map('intval', $userids);
    }

    /**
     * Every (learning path, host course) pair a change in the given course
     * can affect, already aggregated per pair.
     *
     * Used by the live event path: the changed course is a NODE course of
     * some embedding, not necessarily its host. Embeddings whose learning
     * path does not reference the changed course at all are skipped before
     * the more expensive per-option check.
     *
     * @param int $courseid The course whose enrolments changed.
     * @param int $userid The user id.
     * @return array List of ['learningpathid', 'hostcourseid', 'entitled', 'mode'].
     */
    public static function get_affected_pairs(int $courseid, int $userid): array {
        global $DB;

        $embeddings = $DB->get_records(
            'adele',
            null,
            '',
            'id, course, learningpathid, participantslist, hostenrolmentmode'
        );

        $groups = [];
        $pathcache = [];
        foreach (self::normalise_embeddings($embeddings) as $embedding) {
            if (!$embedding['option2'] && !$embedding['option3']) {
                continue;
            }
            $lpid = (int) $embedding['learningpathid'];
            if (!array_key_exists($lpid, $pathcache)) {
                $pathcache[$lpid] = learning_paths::get_learning_path_by_id($lpid) ?: false;
            }
            $learningpath = $pathcache[$lpid];
            if (!$learningpath) {
                continue;
            }
            if (!in_array($courseid, self::get_node_courseids($learningpath, '3'), true)) {
                // The changed course has nothing to do with this learning path.
                continue;
            }

            $entitled = ($embedding['option2'] && self::is_user_entitled_via_option($learningpath, $userid, '2'))
                || ($embedding['option3'] && self::is_user_entitled_via_option($learningpath, $userid, '3'));

            $key = $lpid . ':' . $embedding['courseid'];
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'learningpathid' => $lpid,
                    'hostcourseid' => (int) $embedding['courseid'],
                    'entitled' => false,
                    'mode' => self::MODE_VISIBLE,
                    'moderank' => -1,
                ];
            }
            if ($entitled) {
                $groups[$key]['entitled'] = true;
                $rank = self::mode_rank($embedding['mode']);
                if ($rank > $groups[$key]['moderank']) {
                    $groups[$key]['moderank'] = $rank;
                    $groups[$key]['mode'] = $embedding['mode'];
                }
            }
        }

        return array_values($groups);
    }

    /**
     * Generosity ranking of the visibility modes, highest first:
     * visible > hidden > none.
     *
     * @param string $mode One of the MODE_* constants.
     * @return int Higher is more permissive.
     */
    public static function mode_rank(string $mode): int {
        switch ($mode) {
            case self::MODE_VISIBLE:
                return 2;
            case self::MODE_HIDDEN:
                return 1;
            default:
                return 0;
        }
    }
}

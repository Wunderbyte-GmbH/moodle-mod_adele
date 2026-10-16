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

namespace mod_adele;

use advanced_testcase;

/**
 * Participant sources count only enrolments ADELE did not create itself (#37).
 *
 * Two learning paths share one host course:
 *   path A: embedded with source 3 ("any course of the path"), mode visible
 *   path B: embedded with source 1 ("this course")
 * A manual enrolment into A's node course subscribes the person to A, and A
 * enrols them visibly into the host course. That host enrolment is ADELE's
 * own - it must NOT count as membership for B, or subscriptions would spread
 * from path to path through the shared course.
 *
 * Sources 2 and 3 and the A-4 rule already counted only foreign enrolments;
 * source 1 counted every enrolment, on the event path and when the activity
 * is saved. Both are covered here.
 *
 * @package     mod_adele
 * @copyright   2026 Wunderbyte GmbH
 * @copyright   2026 Ralf Erlebach
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \mod_adele_observer
 * @covers      \mod_adele\local\host_policy::has_foreign_enrolment
 */
final class participant_source_test extends advanced_testcase {
    /** @var \stdClass The shared host course. */
    private $host;

    /** @var int Path A, embedded with source 3. */
    private int $patha;

    /** @var int Path B, embedded with source 1. */
    private int $pathb;

    /** @var \stdClass Path A's (only) node course. */
    private $nodecourse;

    /**
     * Two paths, one shared host course.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest(true);
        if (!class_exists('\enrol_adele\local\reconciler') || !class_exists('\local_adele\enrol_state')) {
            $this->markTestSkipped('enrol_adele and local_adele are required for this test.');
        }
        $this->assertTrue(enrol_is_enabled('adele'), 'enrol_adele must be enabled, or nothing is tested.');

        $this->host = $this->getDataGenerator()->create_course();
        $this->nodecourse = $this->getDataGenerator()->create_course();
        $othernode = $this->getDataGenerator()->create_course();

        $this->patha = $this->path('Pfad A', (int) $this->nodecourse->id);
        $this->pathb = $this->path('Pfad B', (int) $othernode->id);
        $this->embed($this->patha, '3', 'visible');
        $this->embed($this->pathb, '1', 'visible');
    }

    /**
     * A learning path with one starting node.
     *
     * @param string $name Its name.
     * @param int $courseid The node's course.
     * @return int The path id.
     */
    private function path(string $name, int $courseid): int {
        global $DB;
        return (int) $DB->insert_record('local_adele_learning_paths', (object) [
            'name' => $name,
            'description' => '',
            'timecreated' => time(),
            'timemodified' => time(),
            'createdby' => get_admin()->id,
            'json' => json_encode(['tree' => [
                'nodes' => [[
                    'id' => 'dndnode_1',
                    'type' => 'custom',
                    'parentCourse' => ['starting_node'],
                    'data' => ['course_node_id' => [$courseid]],
                ]],
                'edges' => [],
            ]]),
        ]);
    }

    /**
     * Embed a path into the host course.
     *
     * @param int $pathid The path.
     * @param string $sources The participant sources, e.g. "1" or "2,3".
     * @param string $mode The host enrolment mode.
     * @return \stdClass The activity record.
     */
    private function embed(int $pathid, string $sources, string $mode): \stdClass {
        global $DB;
        $record = (object) [
            'course' => $this->host->id,
            'name' => 'Einbettung ' . $pathid,
            'intro' => '',
            'introformat' => 1,
            'learningpathid' => $pathid,
            'participantslist' => $sources,
            'hostenrolmentmode' => $mode,
            'userlist' => 1,
            'view' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $record->id = $DB->insert_record('adele', $record);
        return $record;
    }

    /**
     * Whether the user is subscribed to the path.
     *
     * @param int $pathid The path.
     * @param int $userid The user.
     * @return bool
     */
    private function subscribed(int $pathid, int $userid): bool {
        global $DB;
        return $DB->record_exists('local_adele_path_user', ['learning_path_id' => $pathid, 'user_id' => $userid]);
    }

    /**
     * The precondition the whole test rests on: path A did enrol the person
     * into the host course - otherwise "not subscribed to B" proves nothing.
     *
     * @param int $userid The user.
     */
    private function assert_host_enrolment_by_adele(int $userid): void {
        global $DB;
        $this->assertTrue($DB->record_exists_sql(
            "SELECT 1 FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
              WHERE ue.userid = ? AND e.courseid = ? AND e.enrol = 'adele' AND ue.status = ?",
            [$userid, $this->host->id, ENROL_USER_ACTIVE]
        ), 'Path A must have enrolled the person into the shared host course.');
    }

    /**
     * Event path: the host enrolment ADELE creates must not subscribe to B.
     */
    public function test_adele_host_enrolment_does_not_count_for_source_1(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $this->nodecourse->id, 'student', 'manual');

        $this->assertTrue($this->subscribed($this->patha, $user->id), 'Source 3 must subscribe to path A.');
        $this->assert_host_enrolment_by_adele($user->id);
        $this->assertFalse(
            $this->subscribed($this->pathb, $user->id),
            'Membership created by ADELE for path A must not carry the person into path B.'
        );
    }

    /**
     * Save path: re-saving B's activity must not pick the person up either.
     */
    public function test_saving_the_activity_ignores_adele_enrolments(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $this->nodecourse->id, 'student', 'manual');
        $this->assert_host_enrolment_by_adele($user->id);

        $activity = $DB->get_record('adele', ['learningpathid' => $this->pathb], '*', MUST_EXIST);
        \mod_adele_observer::enroll_all_participants(
            $activity,
            (object) ['courseid' => $this->host->id, 'userid' => get_admin()->id]
        );
        $this->assertFalse($this->subscribed($this->pathb, $user->id), 'The save-time sweep must apply the same rule.');
    }

    /**
     * Control: a real membership of the host course still carries, on both paths.
     */
    public function test_a_real_membership_still_counts(): void {
        global $DB;
        $member = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($member->id, $this->host->id, 'student', 'manual');
        $this->assertTrue($this->subscribed($this->pathb, $member->id), 'Event path: a manual member must be subscribed.');

        $DB->delete_records('local_adele_path_user', ['learning_path_id' => $this->pathb, 'user_id' => $member->id]);
        $activity = $DB->get_record('adele', ['learningpathid' => $this->pathb], '*', MUST_EXIST);
        \mod_adele_observer::enroll_all_participants(
            $activity,
            (object) ['courseid' => $this->host->id, 'userid' => get_admin()->id]
        );
        $this->assertTrue($this->subscribed($this->pathb, $member->id), 'Save path: a manual member must be subscribed.');
    }
}

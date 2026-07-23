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
 * Test for the host-course aggregation rule when several embeddings of the
 * same learning path target the same host course (requirement mod_adele #23).
 *
 * @package mod_adele
 * @copyright 2026 Ralf Erlebach
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_adele;

use advanced_testcase;

/**
 * Test for the host-course aggregation rule when several embeddings of the
 * same learning path target the same host course.
 *
 * Uses direct {local_adele_learning_paths}/{adele} inserts rather than the
 * generator (mirrors the approach already used in enrol_adele's own test
 * suite): sync_host_access_for_node_enrolment() reads the {adele} table
 * directly and never touches course_modules, so a full activity via
 * create_module() is not required to exercise it.
 */
final class host_enrolment_priority_test extends advanced_testcase {
    /**
     * Sets up the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Two embeddings of the SAME learning path in the SAME host course — one
     * narrow (option 2, mode 'none'), one generous (option 3, mode
     * 'visible') — must resolve to the generous outcome, deterministically,
     * regardless of which embedding the aggregation happens to process
     * first. Before the fix, whichever reconcile_host_user() call ran last
     * won outright, which could just as easily have suspended the user.
     *
     * @covers \mod_adele_observer
     */
    public function test_most_generous_embedding_wins_for_shared_host_course(): void {
        global $DB;
        if (!class_exists('\enrol_adele\local\reconciler') || !class_exists('\local_adele\enrol_state')) {
            $this->markTestSkipped('enrol_adele and local_adele are required for this test.');
        }

        $host = $this->getDataGenerator()->create_course();
        $nodecourse = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        // One node, marked as a starting node, granting $nodecourse — this
        // makes the node course qualify under BOTH option 2 (starting node)
        // and option 3 (any node) below.
        $lpid = $DB->insert_record('local_adele_learning_paths', (object) [
            'name' => 'Prioritaets-Testpfad',
            'description' => '',
            'timecreated' => time(),
            'timemodified' => time(),
            'createdby' => $user->id,
            'json' => json_encode([
                'tree' => [
                    'nodes' => [[
                        'id' => 'dndnode_1',
                        'type' => 'courseNode',
                        'parentCourse' => ['starting_node'],
                        'data' => ['course_node_id' => [(int) $nodecourse->id]],
                    ]],
                    'edges' => [],
                ],
            ]),
        ]);

        // Embedding 1 (narrow): option 2, mode 'none' — never grants access.
        $DB->insert_record('adele', (object) [
            'course' => $host->id,
            'name' => 'Embedding schmal',
            'intro' => '',
            'introformat' => 1,
            'learningpathid' => $lpid,
            'participantslist' => '2',
            'hostenrolmentmode' => 'none',
            'userlist' => 1,
            'view' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        // Embedding 2 (generous): option 3, mode 'visible'.
        $DB->insert_record('adele', (object) [
            'course' => $host->id,
            'name' => 'Embedding grosszuegig',
            'intro' => '',
            'introformat' => 1,
            'learningpathid' => $lpid,
            'participantslist' => '3',
            'hostenrolmentmode' => 'visible',
            'userlist' => 1,
            'view' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        // Enrolling into the node course fires user_enrolment_created
        // site-wide, reaching mod_adele's observer and, through it, the
        // aggregation under test.
        $this->getDataGenerator()->enrol_user($user->id, $nodecourse->id, 'student', 'manual');

        $instance = $DB->get_record('enrol', [
            'enrol' => 'adele',
            'courseid' => $host->id,
            'customint1' => $lpid,
            'customint2' => \enrol_adele\local\instance_manager::KIND_HOST,
        ]);
        $this->assertNotFalse($instance, 'The shared host-course instance was never created.');
        $ue = $DB->get_record('user_enrolments', ['enrolid' => $instance->id, 'userid' => $user->id]);
        $this->assertNotFalse($ue, 'No enrolment record exists for the user in the shared host course.');
        $this->assertEquals(
            ENROL_USER_ACTIVE,
            $ue->status,
            'The generous embedding (visible) should win over the narrow one (none).'
        );

        // Only ONE instance was created for the pair, not one per embedding.
        $allinstances = $DB->get_records('enrol', [
            'enrol' => 'adele',
            'courseid' => $host->id,
            'customint1' => $lpid,
            'customint2' => \enrol_adele\local\instance_manager::KIND_HOST,
        ]);
        $this->assertCount(1, $allinstances);
    }

    /**
     * Requirement E-16: the one-time activity-save sweep must aggregate
     * across sibling embeddings the same way the live observer does, not
     * just apply its own embedding's mode in isolation. Simulates a teacher
     * saving the generous embedding first, then the narrow one — the sweep
     * for the SECOND (narrow) save must not downgrade the access the first
     * (generous) one already granted.
     *
     * @covers \mod_adele_observer::enroll_any_nodes_participants
     * @covers \mod_adele_observer::enroll_starting_nodes_participants
     */
    public function test_sweep_aggregates_across_sibling_embeddings(): void {
        global $DB;
        if (!class_exists('\enrol_adele\local\reconciler') || !class_exists('\local_adele\enrol_state')) {
            $this->markTestSkipped('enrol_adele and local_adele are required for this test.');
        }

        $host = $this->getDataGenerator()->create_course();
        $nodecourse = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $lpid = $DB->insert_record('local_adele_learning_paths', (object) [
            'name' => 'Sweep-Testpfad',
            'description' => '',
            'timecreated' => time(),
            'timemodified' => time(),
            'createdby' => $user->id,
            'json' => json_encode([
                'tree' => [
                    'nodes' => [[
                        'id' => 'dndnode_1',
                        'type' => 'courseNode',
                        'parentCourse' => ['starting_node'],
                        'data' => ['course_node_id' => [(int) $nodecourse->id]],
                    ]],
                    'edges' => [],
                ],
            ]),
        ]);

        // Enrol into the node course BEFORE either embedding exists, so the
        // live observer (also triggered by this call) has nothing to act on
        // yet — isolates this test to the sweep methods called explicitly
        // below, rather than the already-covered live-aggregation path.
        $this->getDataGenerator()->enrol_user($user->id, $nodecourse->id, 'student', 'manual');

        $narrowid = $DB->insert_record('adele', (object) [
            'course' => $host->id,
            'name' => 'Embedding schmal',
            'intro' => '',
            'introformat' => 1,
            'learningpathid' => $lpid,
            'participantslist' => '2',
            'hostenrolmentmode' => 'none',
            'userlist' => 1,
            'view' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $narrow = $DB->get_record('adele', ['id' => $narrowid]);

        $generousid = $DB->insert_record('adele', (object) [
            'course' => $host->id,
            'name' => 'Embedding grosszuegig',
            'intro' => '',
            'introformat' => 1,
            'learningpathid' => $lpid,
            'participantslist' => '3',
            'hostenrolmentmode' => 'visible',
            'userlist' => 1,
            'view' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $generous = $DB->get_record('adele', ['id' => $generousid]);

        $actor = (object) ['userid' => 2];

        // Simulate saving the generous embedding first.
        \mod_adele_observer::enroll_any_nodes_participants($generous, $actor);

        $instance = $DB->get_record('enrol', [
            'enrol' => 'adele',
            'courseid' => $host->id,
            'customint1' => $lpid,
            'customint2' => \enrol_adele\local\instance_manager::KIND_HOST,
        ]);
        $this->assertNotFalse($instance);
        $ue = $DB->get_record('user_enrolments', ['enrolid' => $instance->id, 'userid' => $user->id]);
        $this->assertNotFalse($ue);
        $this->assertEquals(ENROL_USER_ACTIVE, $ue->status, 'The generous sweep should have granted active access.');

        // Now simulate saving the narrow (mode 'none') embedding afterwards.
        \mod_adele_observer::enroll_starting_nodes_participants($narrow, $actor);

        $ue = $DB->get_record('user_enrolments', ['enrolid' => $instance->id, 'userid' => $user->id]);
        $this->assertEquals(
            ENROL_USER_ACTIVE,
            $ue->status,
            'The later, narrower sweep must not downgrade access the generous sibling embedding already granted.'
        );
    }
}

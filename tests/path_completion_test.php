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
use completion_info;
use mod_adele\completion\custom_completion;
use mod_adele\local\path_completion;

/**
 * The completion rule "learning path finished".
 *
 * The form offered the rule, but nothing evaluated it: there was no
 * custom_completion class, and nothing re-evaluated the activity when a
 * learner's path changed. These tests go through the real chain - a teacher's
 * change of a learner's path, local_adele's recomputation, this plugin's
 * observer - so they also pin the order of the two observers of
 * user_path_updated: local_adele stores the path first, mod_adele reads it.
 *
 * @package     mod_adele
 * @category    test
 * @copyright   2026 Wunderbyte GmbH
 * @copyright   2026 Ralf Erlebach
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \mod_adele\completion\custom_completion
 * @covers      \mod_adele\local\path_completion
 * @covers      \mod_adele_observer::learner_path_updated
 */
final class path_completion_test extends advanced_testcase {
    /** @var \stdClass The course embedding the path, completion enabled. */
    private $host;

    /** @var int The learning path: two nodes in a row. */
    private int $pathid;

    /** @var \stdClass The learner. */
    private $learner;

    /**
     * A path of two nodes and a learner in the embedding course.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest(true);
        if (!class_exists('\local_adele\learning_paths')) {
            $this->markTestSkipped('local_adele is required for this test.');
        }
        $gen = $this->getDataGenerator();
        $this->host = $gen->create_course(['enablecompletion' => 1]);
        $first = $gen->create_course();
        $second = $gen->create_course();
        $this->pathid = (int) $DB->insert_record('local_adele_learning_paths', (object) [
            'name' => 'Pfad',
            'description' => '',
            'timecreated' => time(),
            'timemodified' => time(),
            'createdby' => get_admin()->id,
            'json' => json_encode(['tree' => [
                'nodes' => [
                    [
                        'id' => 'dndnode_1',
                        'type' => 'custom',
                        'parentCourse' => ['starting_node'],
                        'childCourse' => ['dndnode_2'],
                        'data' => ['course_node_id' => [(int) $first->id]],
                    ],
                    [
                        'id' => 'dndnode_2',
                        'type' => 'custom',
                        'parentCourse' => ['dndnode_1'],
                        'childCourse' => [],
                        'data' => ['course_node_id' => [(int) $second->id]],
                    ],
                ],
                'edges' => [['id' => 'e1', 'source' => 'dndnode_1', 'target' => 'dndnode_2']],
            ]]),
        ]);
        $this->setAdminUser();
        $this->learner = $gen->create_user();
    }

    /**
     * Embed the path into the host course and enrol the learner.
     *
     * @param int $rule 1 = the rule "learning path finished" is on.
     * @return \stdClass The course module.
     */
    private function embed(int $rule): \stdClass {
        $activity = $this->getDataGenerator()->create_module('adele', [
            'course' => $this->host->id,
            'learningpathid' => $this->pathid,
            'participantslist' => '1',
            'userlist' => 1,
            'view' => 1,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionlearningpathfinished' => $rule,
        ]);
        $this->getDataGenerator()->enrol_user($this->learner->id, $this->host->id, 'student');
        return get_coursemodule_from_instance('adele', $activity->id, $this->host->id, false, MUST_EXIST);
    }

    /**
     * As a teacher: set the master completion of the given nodes, clear it on
     * the others, and save - the way the path view does.
     *
     * @param string[] $completed Node ids whose master completion is set.
     */
    private function master_completion(array $completed): void {
        global $DB;
        $json = json_decode($DB->get_field('local_adele_path_user', 'json', [
            'learning_path_id' => $this->pathid,
            'user_id' => $this->learner->id,
        ], MUST_EXIST), true);
        foreach ($json['tree']['nodes'] as &$node) {
            $node['data']['completion']['master']['completion'] = in_array($node['id'], $completed);
        }
        unset($node);
        \local_adele\learning_paths::save_learning_user_relation([
            'courseid' => $this->host->id,
            'params' => json_encode([
                'route' => ['learningpathId' => $this->pathid, 'userId' => (int) $this->learner->id],
                'nodes' => $json['tree']['nodes'],
            ]),
        ]);
    }

    /**
     * The learner's completion state of the activity.
     *
     * @param \stdClass $cm The course module.
     * @return int
     */
    private function state(\stdClass $cm): int {
        $completion = new completion_info(get_course($this->host->id));
        return (int) $completion->get_data($cm, false, $this->learner->id)->completionstate;
    }

    /**
     * Finishing the path completes the activity; a half-finished path does
     * not; taking a completion back takes the activity completion back.
     */
    public function test_activity_follows_the_path_in_both_directions(): void {
        $cm = $this->embed(1);
        $this->assertFalse(path_completion::is_finished($this->pathid, (int) $this->learner->id));
        $this->assertSame(COMPLETION_INCOMPLETE, $this->state($cm), 'Nothing completed yet.');

        $this->master_completion(['dndnode_1']);
        $this->assertFalse(path_completion::is_finished($this->pathid, (int) $this->learner->id));
        $this->assertSame(COMPLETION_INCOMPLETE, $this->state($cm), 'One node of two is not the path.');

        $this->master_completion(['dndnode_1', 'dndnode_2']);
        $this->assertTrue(path_completion::is_finished($this->pathid, (int) $this->learner->id));
        $this->assertSame(COMPLETION_COMPLETE, $this->state($cm), 'The path is finished: the activity is completed.');

        $this->master_completion(['dndnode_1']);
        $this->assertFalse(path_completion::is_finished($this->pathid, (int) $this->learner->id));
        $this->assertSame(COMPLETION_INCOMPLETE, $this->state($cm), 'Completion taken back: the activity follows.');
    }

    /**
     * Without the rule the activity does not complete on the path.
     */
    public function test_activity_without_the_rule_is_left_alone(): void {
        $cm = $this->embed(0);
        $this->master_completion(['dndnode_1', 'dndnode_2']);
        $this->assertTrue(path_completion::is_finished($this->pathid, (int) $this->learner->id));
        $this->assertSame(COMPLETION_INCOMPLETE, $this->state($cm));
    }

    /**
     * Somebody who is not on the path has not finished it.
     */
    public function test_nobody_finishes_a_path_they_are_not_on(): void {
        $stranger = $this->getDataGenerator()->create_user();
        $this->assertFalse(path_completion::is_finished($this->pathid, (int) $stranger->id));
        $this->assertFalse(path_completion::is_finished(0, (int) $stranger->id));
    }

    /**
     * Moodle finds the rule: it is defined, described, and has a place in the
     * order the conditions are shown in.
     */
    public function test_rule_is_known_to_moodle(): void {
        $cm = \cm_info::create($this->embed(1), (int) $this->learner->id);
        $this->assertSame(
            custom_completion::class,
            ltrim(\core_completion\activity_custom_completion::get_cm_completion_class('adele'), '\\')
        );
        $custom = new custom_completion($cm, (int) $this->learner->id);
        $this->assertSame([path_completion::RULE], custom_completion::get_defined_custom_rules());
        $this->assertArrayHasKey(path_completion::RULE, $custom->get_custom_rule_descriptions());
        $this->assertContains(path_completion::RULE, $custom->get_sort_order());
        $this->assertSame(COMPLETION_INCOMPLETE, $custom->get_state(path_completion::RULE));
    }
}

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
 * A change of the site's graded roles reaches the people who already hold a
 * role that only now counts (local_adele #593).
 *
 * Who takes part in a course follows the graded roles. People are subscribed
 * when they are enrolled or given a role; for somebody who is in the course
 * already, under a role the site adds to its graded roles later, there is no
 * such event. The change of the setting queues every activity's participant
 * sources to be applied again.
 *
 * @package     mod_adele
 * @copyright   2026 Wunderbyte GmbH
 * @copyright   2026 Ralf Erlebach
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \mod_adele_observer::site_config_changed
 * @covers      \mod_adele\task\apply_participant_sources_adhoc
 */
final class graded_roles_test extends advanced_testcase {
    /** @var \stdClass The course that embeds the path. */
    private $host;

    /** @var int The learning path. */
    private int $pathid;

    /**
     * One path, embedded with source 1 ("this course").
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest(true);
        if (!class_exists('\enrol_adele\local\reconciler') || !class_exists('\local_adele\enrol_state')) {
            $this->markTestSkipped('enrol_adele and local_adele are required for this test.');
        }
        $this->host = $this->getDataGenerator()->create_course();
        $node = $this->getDataGenerator()->create_course();
        $this->pathid = (int) $DB->insert_record('local_adele_learning_paths', (object) [
            'name' => 'Pfad',
            'description' => '',
            'timecreated' => time(),
            'timemodified' => time(),
            'createdby' => get_admin()->id,
            'json' => json_encode(['tree' => [
                'nodes' => [[
                    'id' => 'dndnode_1',
                    'type' => 'custom',
                    'parentCourse' => ['starting_node'],
                    'data' => ['course_node_id' => [(int) $node->id]],
                ]],
                'edges' => [],
            ]]),
        ]);
        $this->setAdminUser();
        $this->getDataGenerator()->create_module('adele', [
            'course' => $this->host->id,
            'learningpathid' => $this->pathid,
            'participantslist' => '1',
            'hostenrolmentmode' => 'visible',
            'userlist' => 1,
            'view' => 1,
        ]);
    }

    /**
     * Whether somebody is a learner of the path.
     *
     * @param int $userid The person.
     * @return bool
     */
    private function subscribed(int $userid): bool {
        global $DB;
        return $DB->record_exists('local_adele_path_user', [
            'user_id' => $userid,
            'learning_path_id' => $this->pathid,
            'status' => 'active',
        ]);
    }

    /**
     * Change the graded roles the way the settings page does: the value, and
     * the log entry whose event the plugins hear.
     *
     * @param string $name The setting.
     * @param string $value Its new value.
     */
    private function change_setting(string $name, string $value): void {
        global $CFG;
        $old = $CFG->{$name} ?? null;
        set_config($name, $value);
        add_to_config_log($name, $old, $value, null);
    }

    /**
     * A member of the course under a role that is no graded role is no
     * learner - until the site makes the role a graded role.
     */
    public function test_existing_holders_become_learners_when_their_role_becomes_graded(): void {
        global $DB, $CFG;
        $gen = $this->getDataGenerator();
        $role = $gen->create_role(['shortname' => 'lernende']);
        $member = $gen->create_user();
        $gen->enrol_user($member->id, $this->host->id, $role);
        $teacher = $gen->create_and_enrol($this->host, 'editingteacher');
        $this->assertFalse($this->subscribed((int) $member->id), 'the precondition: the role does not count');

        $this->change_setting('gradebookroles', $CFG->gradebookroles . ',' . $role);

        $this->assertFalse($this->subscribed((int) $member->id), 'queued, not done while the settings page is saved');
        $this->assertSame(1, $DB->count_records('task_adhoc', [
            'classname' => '\\mod_adele\\task\\apply_participant_sources_adhoc',
        ]), 'one task per learning path activity');
        $this->runAdhocTasks('\\mod_adele\\task\\apply_participant_sources_adhoc');

        $this->assertTrue($this->subscribed((int) $member->id), 'the member is a learner now');
        $this->assertFalse($this->subscribed((int) $teacher->id), 'the teacher still is none');
    }

    /**
     * Any other setting is none of this plugin's business.
     */
    public function test_other_settings_queue_nothing(): void {
        global $DB;
        $this->change_setting('grade_decimalpoints', '3');
        // A plugin's setting of the same name is not the site's.
        add_to_config_log('gradebookroles', '', '5', 'some_plugin');
        $this->assertSame(0, $DB->count_records('task_adhoc', [
            'classname' => '\\mod_adele\\task\\apply_participant_sources_adhoc',
        ]));
    }

    /**
     * The task survives an activity that was deleted while it waited.
     */
    public function test_task_ignores_a_deleted_activity(): void {
        $task = new \mod_adele\task\apply_participant_sources_adhoc();
        $task->set_custom_data(['instanceid' => 999999]);
        $task->execute();
        $this->assertTrue(true, 'no error');
    }
}

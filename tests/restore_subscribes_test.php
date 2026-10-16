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
 * A restored embedding subscribes the course's participants like a new one.
 *
 * A restore from the recycle bin (or a backup, an import) fires no
 * course_module_created event, so the observer that subscribes participants
 * never ran for it (measured 2026-10-15). The test drives exactly that path:
 * delete the activity into the course recycle bin, restore it from there.
 * (Duplicating an activity is no substitute: duplicate_module() fires
 * course_module_created itself, and the test passed without the fix.)
 *
 * @package    mod_adele
 * @category   test
 * @copyright  2026 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \restore_adele_activity_structure_step
 * @covers     \adele_delete_instance
 */
final class restore_subscribes_test extends advanced_testcase {
    /**
     * Skip without local_adele.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists('\local_adele\learning_paths')) {
            $this->markTestSkipped('local_adele is required.');
        }
    }

    /**
     * Restored from the recycle bin, the embedding subscribes the member
     * again, whose subscription had gone; nobody outside the course.
     *
     * @return void
     */
    public function test_restored_embedding_subscribes_the_participants(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();

        $node = $this->getDataGenerator()->create_course();
        $host = $this->getDataGenerator()->create_course();
        $json = ['tree' => ['nodes' => [[
            'id' => 'dndnode_1',
            'type' => 'courseNode',
            'parentCourse' => ['starting_node'],
            'data' => ['course_node_id' => [(int) $node->id]],
        ]], 'edges' => []]];
        $lpid = (int) $DB->insert_record('local_adele_learning_paths', (object) [
            'name' => 'Wiederherstellungs-Testpfad',
            'description' => '',
            'timecreated' => time(),
            'timemodified' => time(),
            'createdby' => 2,
            'json' => json_encode($json),
        ]);
        $member = $this->getDataGenerator()->create_user();
        $outsider = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($member->id, $host->id, 'student', 'manual');

        $adele = $this->getDataGenerator()->create_module('adele', [
            'course' => $host->id,
            'name' => 'Einbettung',
            'learningpathid' => $lpid,
            'participantslist' => [1],
            'hostenrolmentmode' => 'visible',
            'userlist' => 1,
            'view' => 1,
        ]);
        $this->assertTrue(
            $DB->record_exists('local_adele_path_user', ['learning_path_id' => $lpid, 'user_id' => $member->id]),
            'Precondition: creating the embedding subscribes the member.'
        );

        // Deleted into the course recycle bin ...
        set_config('coursebinenable', 1, 'tool_recyclebin');
        $cm = get_coursemodule_from_instance('adele', $adele->id, $host->id, false, MUST_EXIST);
        course_delete_module($cm->id);
        $this->assertFalse($DB->record_exists('adele', ['id' => $adele->id]), 'The activity is gone.');
        // Its subscriptions go with the queued reconcile; here they are removed directly.
        $DB->delete_records('local_adele_path_user', ['learning_path_id' => $lpid]);

        // ... and restored from there.
        $bin = new \tool_recyclebin\course_bin($host->id);
        $items = $bin->get_items();
        $this->assertCount(1, $items, 'The activity is in the recycle bin.');
        $bin->restore_item(reset($items));
        $this->assertTrue($DB->record_exists('adele', ['course' => $host->id, 'learningpathid' => $lpid]), 'It is back.');

        $this->assertTrue(
            $DB->record_exists('local_adele_path_user', ['learning_path_id' => $lpid, 'user_id' => $member->id]),
            'The restored embedding subscribes the member again.'
        );
        $this->assertFalse(
            $DB->record_exists('local_adele_path_user', ['learning_path_id' => $lpid, 'user_id' => $outsider->id]),
            'Nobody outside the course.'
        );
    }
}

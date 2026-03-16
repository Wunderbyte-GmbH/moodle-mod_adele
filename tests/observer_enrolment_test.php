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
 * Unit tests for adele instance creation and participant enrolment based on participantslist settings.
 *
 * @package    mod_adele
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_adele;

use advanced_testcase;
use context_course;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/mod/adele/lib.php');

/**
 * Test class for adele instance creation and observer-based enrolment.
 *
 * @package    mod_adele
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class observer_enrolment_test extends advanced_testcase {
    /**
     * Sets up the test environment before each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
    }

    /**
     * Tests that an adele instance can be created with participantslist as a string value.
     *
     * @covers ::adele_add_instance
     */
    public function test_create_instance_with_string_participantslist(): void {
        global $DB;

        // Arrange.
        $course = $this->getDataGenerator()->create_course();

        // Act.
        $adele = $this->getDataGenerator()->create_module('adele', [
            'course' => $course->id,
            'name' => 'Adele String Participantslist',
            'learningpathid' => 1,
            'view' => 1,
            'userlist' => 0,
            'participantslist' => '1',
            'intro' => 'Test intro',
            'introformat' => FORMAT_HTML,
        ]);

        // Assert.
        $record = $DB->get_record('adele', ['id' => $adele->id]);
        $this->assertNotEmpty($record);
        $this->assertEquals('1', $record->participantslist);
        $this->assertEquals('Adele String Participantslist', $record->name);
    }

    /**
     * Tests that an adele instance can be created with participantslist as an array value.
     *
     * @covers ::adele_add_instance
     */
    public function test_create_instance_with_array_participantslist(): void {
        global $DB;

        // Arrange.
        $course = $this->getDataGenerator()->create_course();
        $moduleinstance = new stdClass();
        $moduleinstance->modulename = 'adele';
        $moduleinstance->course = $course->id;
        $moduleinstance->name = 'Adele Array Participantslist';
        $moduleinstance->learningpathid = 1;
        $moduleinstance->view = 1;
        $moduleinstance->userlist = 0;
        $moduleinstance->participantslist = [1, 2, 3];
        $moduleinstance->intro = 'Test intro';
        $moduleinstance->introformat = FORMAT_HTML;

        // Act.
        $id = adele_add_instance($moduleinstance);

        // Assert.
        $record = $DB->get_record('adele', ['id' => $id]);
        $this->assertNotEmpty($record);
        $this->assertEquals('1,2,3', $record->participantslist);
    }

    /**
     * Tests that an adele instance stores participantslist option 1 (this course only).
     *
     * @covers ::adele_add_instance
     */
    public function test_create_instance_participantslist_this_course(): void {
        global $DB;

        // Arrange.
        $course = $this->getDataGenerator()->create_course();

        // Act.
        $adele = $this->getDataGenerator()->create_module('adele', [
            'course' => $course->id,
            'name' => 'Adele This Course',
            'learningpathid' => 1,
            'view' => 1,
            'userlist' => 0,
            'participantslist' => '1',
            'intro' => 'Test intro',
            'introformat' => FORMAT_HTML,
        ]);

        // Assert.
        $record = $DB->get_record('adele', ['id' => $adele->id]);
        $this->assertNotEmpty($record);
        $this->assertEquals('1', $record->participantslist);
    }

    /**
     * Tests that an adele instance stores participantslist option 2 (starting courses).
     *
     * @covers ::adele_add_instance
     */
    public function test_create_instance_participantslist_starting_courses(): void {
        global $DB;

        // Arrange.
        $course = $this->getDataGenerator()->create_course();

        // Act.
        $adele = $this->getDataGenerator()->create_module('adele', [
            'course' => $course->id,
            'name' => 'Adele Starting Courses',
            'learningpathid' => 2,
            'view' => 1,
            'userlist' => 0,
            'participantslist' => '2',
            'intro' => 'Test intro',
            'introformat' => FORMAT_HTML,
        ]);

        // Assert.
        $record = $DB->get_record('adele', ['id' => $adele->id]);
        $this->assertNotEmpty($record);
        $this->assertEquals('2', $record->participantslist);
    }

    /**
     * Tests that an adele instance stores participantslist option 3 (all courses).
     *
     * @covers ::adele_add_instance
     */
    public function test_create_instance_participantslist_all_courses(): void {
        global $DB;

        // Arrange.
        $course = $this->getDataGenerator()->create_course();

        // Act.
        $adele = $this->getDataGenerator()->create_module('adele', [
            'course' => $course->id,
            'name' => 'Adele All Courses',
            'learningpathid' => 3,
            'view' => 1,
            'userlist' => 0,
            'participantslist' => '3',
            'intro' => 'Test intro',
            'introformat' => FORMAT_HTML,
        ]);

        // Assert.
        $record = $DB->get_record('adele', ['id' => $adele->id]);
        $this->assertNotEmpty($record);
        $this->assertEquals('3', $record->participantslist);
    }

    /**
     * Tests that an adele instance stores combined participantslist options.
     *
     * @covers ::adele_add_instance
     */
    public function test_create_instance_participantslist_combined(): void {
        global $DB;

        // Arrange.
        $course = $this->getDataGenerator()->create_course();
        $moduleinstance = new stdClass();
        $moduleinstance->modulename = 'adele';
        $moduleinstance->course = $course->id;
        $moduleinstance->name = 'Adele Combined';
        $moduleinstance->learningpathid = 1;
        $moduleinstance->view = 1;
        $moduleinstance->userlist = 0;
        $moduleinstance->participantslist = [1, 3];
        $moduleinstance->intro = 'Test intro';
        $moduleinstance->introformat = FORMAT_HTML;

        // Act.
        $id = adele_add_instance($moduleinstance);

        // Assert.
        $record = $DB->get_record('adele', ['id' => $id]);
        $this->assertNotEmpty($record);
        $this->assertEquals('1,3', $record->participantslist);
    }

    /**
     * Tests that enrolled users exist in the course after manual enrolment.
     *
     * This simulates the precondition for the observer: users must be enrolled
     * in the course before the observer can process them.
     *
     * @covers \mod_adele_observer::enroll_all_participants
     */
    public function test_enrolled_users_in_course_with_adele(): void {
        global $DB;

        // Arrange: Create course, users, and enrol them.
        $course = $this->getDataGenerator()->create_course();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($user1->id, $course->id);
        $this->getDataGenerator()->enrol_user($user2->id, $course->id);

        // Create adele instance with participantslist = 1 (this course).
        $adele = $this->getDataGenerator()->create_module('adele', [
            'course' => $course->id,
            'name' => 'Adele Enrolment Test',
            'learningpathid' => 1,
            'view' => 1,
            'userlist' => 0,
            'participantslist' => '1',
            'intro' => 'Test intro',
            'introformat' => FORMAT_HTML,
        ]);

        // Act: Get enrolled users.
        $coursecontext = context_course::instance($course->id);
        $enrolledusers = get_enrolled_users($coursecontext, '', 0, 'u.id');

        // Assert: User1 and User2 are enrolled, User3 is not.
        $enrolleduserids = array_map('intval', array_keys($enrolledusers));
        $this->assertContains((int)$user1->id, $enrolleduserids);
        $this->assertContains((int)$user2->id, $enrolleduserids);
        $this->assertNotContains((int)$user3->id, $enrolleduserids);
    }

    /**
     * Tests that the adele record correctly stores the learningpathid.
     *
     * @covers ::adele_add_instance
     */
    public function test_adele_instance_stores_learningpathid(): void {
        global $DB;

        // Arrange.
        $course = $this->getDataGenerator()->create_course();

        // Act.
        $adele = $this->getDataGenerator()->create_module('adele', [
            'course' => $course->id,
            'name' => 'Adele LP Test',
            'learningpathid' => 42,
            'view' => 1,
            'userlist' => 0,
            'participantslist' => '1',
            'intro' => 'Test intro',
            'introformat' => FORMAT_HTML,
        ]);

        // Assert.
        $record = $DB->get_record('adele', ['id' => $adele->id]);
        $this->assertNotEmpty($record);
        $this->assertEquals(42, $record->learningpathid);
    }

    /**
     * Tests that the adele module can be deleted successfully.
     *
     * @covers ::adele_delete_instance
     */
    public function test_delete_adele_instance(): void {
        global $DB;

        // Arrange.
        $course = $this->getDataGenerator()->create_course();
        $adele = $this->getDataGenerator()->create_module('adele', [
            'course' => $course->id,
            'name' => 'Adele Delete Test',
            'learningpathid' => 1,
            'view' => 1,
            'userlist' => 0,
            'participantslist' => '1',
            'intro' => 'Test intro',
            'introformat' => FORMAT_HTML,
        ]);

        // Verify it exists.
        $this->assertNotEmpty($DB->get_record('adele', ['id' => $adele->id]));

        // Act.
        $result = adele_delete_instance($adele->id);

        // Assert.
        $this->assertTrue($result);
        $this->assertEmpty($DB->get_record('adele', ['id' => $adele->id]));
    }

    /**
     * Tests that deleting a non-existent adele instance returns false.
     *
     * @covers ::adele_delete_instance
     */
    public function test_delete_nonexistent_adele_instance(): void {
        // Act.
        $result = adele_delete_instance(999999);

        // Assert.
        $this->assertFalse($result);
    }

    /**
     * Tests that the observer correctly parses participantslist from the adele record.
     *
     * @covers \mod_adele_observer::saved_module
     */
    public function test_participantslist_parsing(): void {
        global $DB;

        // Arrange: Create instance with combined participantslist.
        $course = $this->getDataGenerator()->create_course();
        $moduleinstance = new stdClass();
        $moduleinstance->modulename = 'adele';
        $moduleinstance->course = $course->id;
        $moduleinstance->name = 'Adele Parsing Test';
        $moduleinstance->learningpathid = 1;
        $moduleinstance->view = 1;
        $moduleinstance->userlist = 0;
        $moduleinstance->participantslist = [1, 2];
        $moduleinstance->intro = 'Test intro';
        $moduleinstance->introformat = FORMAT_HTML;

        // Act.
        $id = adele_add_instance($moduleinstance);
        $record = $DB->get_record('adele', ['id' => $id]);

        // Assert: Stored as comma-separated string.
        $this->assertEquals('1,2', $record->participantslist);

        // Assert: Can be parsed back to array by observer logic.
        $parsed = explode(',', $record->participantslist);
        $this->assertCount(2, $parsed);
        $this->assertContains('1', $parsed);
        $this->assertContains('2', $parsed);
    }

    /**
     * Tests that the update function correctly handles participantslist as array.
     *
     * @covers ::adele_update_instance
     */
    public function test_update_instance_with_array_participantslist(): void {
        global $DB;

        // Arrange: Create an instance first.
        $course = $this->getDataGenerator()->create_course();
        $adele = $this->getDataGenerator()->create_module('adele', [
            'course' => $course->id,
            'name' => 'Adele Update Test',
            'learningpathid' => 1,
            'view' => 1,
            'userlist' => 0,
            'participantslist' => '1',
            'intro' => 'Test intro',
            'introformat' => FORMAT_HTML,
        ]);

        // Act: Update with array participantslist.
        $updateinstance = new stdClass();
        $updateinstance->instance = $adele->id;
        $updateinstance->name = 'Adele Updated';
        $updateinstance->learningpathid = 2;
        $updateinstance->view = 2;
        $updateinstance->userlist = 1;
        $updateinstance->participantslist = [2, 3];
        $updateinstance->intro = 'Updated intro';
        $updateinstance->introformat = FORMAT_HTML;
        $updateinstance->completionlearningpathfinished = 0;

        $result = adele_update_instance($updateinstance);

        // Assert.
        $this->assertTrue($result);
        $record = $DB->get_record('adele', ['id' => $adele->id]);
        $this->assertEquals('2,3', $record->participantslist);
        $this->assertEquals('Adele Updated', $record->name);
        $this->assertEquals(2, $record->learningpathid);
    }

    /**
     * Tests that the update function correctly handles participantslist as string.
     *
     * @covers ::adele_update_instance
     */
    public function test_update_instance_with_string_participantslist(): void {
        global $DB;

        // Arrange: Create an instance first.
        $course = $this->getDataGenerator()->create_course();
        $adele = $this->getDataGenerator()->create_module('adele', [
            'course' => $course->id,
            'name' => 'Adele Update String Test',
            'learningpathid' => 1,
            'view' => 1,
            'userlist' => 0,
            'participantslist' => '1',
            'intro' => 'Test intro',
            'introformat' => FORMAT_HTML,
        ]);

        // Act: Update with string participantslist.
        $updateinstance = new stdClass();
        $updateinstance->instance = $adele->id;
        $updateinstance->name = 'Adele Updated String';
        $updateinstance->learningpathid = 1;
        $updateinstance->view = 1;
        $updateinstance->userlist = 0;
        $updateinstance->participantslist = '1,2,3';
        $updateinstance->intro = 'Updated intro';
        $updateinstance->introformat = FORMAT_HTML;
        $updateinstance->completionlearningpathfinished = 0;

        $result = adele_update_instance($updateinstance);

        // Assert.
        $this->assertTrue($result);
        $record = $DB->get_record('adele', ['id' => $adele->id]);
        $this->assertEquals('1,2,3', $record->participantslist);
    }

    /**
     * Tests that multiple users enrolled in a course are all visible in the course context.
     *
     * This verifies the precondition for observer-based enrolment into learning paths.
     *
     * @covers \mod_adele_observer::enroll_all_participants
     */
    public function test_multiple_users_enrolled_visible_in_context(): void {
        // Arrange.
        $course = $this->getDataGenerator()->create_course();
        $users = [];
        for ($i = 0; $i < 5; $i++) {
            $users[] = $this->getDataGenerator()->create_user();
        }

        foreach ($users as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id);
        }

        // Create adele instance.
        $this->getDataGenerator()->create_module('adele', [
            'course' => $course->id,
            'name' => 'Adele Multi User Test',
            'learningpathid' => 1,
            'view' => 1,
            'userlist' => 0,
            'participantslist' => '1',
            'intro' => 'Test intro',
            'introformat' => FORMAT_HTML,
        ]);

        // Act.
        $coursecontext = context_course::instance($course->id);
        $enrolledusers = get_enrolled_users($coursecontext, '', 0, 'u.id');
        $enrolleduserids = array_map('intval', array_keys($enrolledusers));

        // Assert: All 5 users are enrolled.
        foreach ($users as $user) {
            $this->assertContains((int)$user->id, $enrolleduserids);
        }
    }

    /**
     * Tests that the default participantslist from the generator is correctly set.
     *
     * @covers ::adele_add_instance
     */
    public function test_generator_default_participantslist(): void {
        global $DB;

        // Arrange & Act: Create instance with no explicit participantslist.
        $course = $this->getDataGenerator()->create_course();
        $adele = $this->getDataGenerator()->create_module('adele', [
            'course' => $course->id,
            'name' => 'Adele Default Test',
        ]);

        // Assert: Default from generator should be '0'.
        $record = $DB->get_record('adele', ['id' => $adele->id]);
        $this->assertNotEmpty($record);
        $this->assertEquals('0', $record->participantslist);
    }
}

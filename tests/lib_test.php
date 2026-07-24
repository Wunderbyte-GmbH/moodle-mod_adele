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

namespace mod_adele;

use advanced_testcase;

/**
 * Unit tests for the mod_adele instance lib functions.
 *
 * @package    mod_adele
 * @copyright  2026 cbadusch
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class lib_test extends advanced_testcase {
    /** @var \stdClass */
    private $course;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        require_once(__DIR__ . '/../lib.php');
        $this->course = $this->getDataGenerator()->create_course();
    }

    /**
     * Build a form-shaped adele instance record.
     *
     * @param array $overrides Fields to override.
     * @return \stdClass
     */
    private function make_instance(array $overrides = []): \stdClass {
        return (object) array_merge([
            'course' => $this->course->id,
            'name' => 'Test adele',
            'learningpathid' => 1,
            'view' => 1,
            'userlist' => 1,
            'participantslist' => [1, 2],
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'timemodified' => 0,
            'completionlearningpathfinished' => 0,
        ], $overrides);
    }

    /**
     * adele_supports reports the features the module implements.
     *
     * @covers ::adele_supports
     */
    public function test_supports(): void {
        $this->assertTrue(adele_supports(FEATURE_MOD_INTRO));
        $this->assertTrue(adele_supports(FEATURE_BACKUP_MOODLE2));
        $this->assertTrue(adele_supports(FEATURE_COMPLETION_TRACKS_VIEWS));
        $this->assertNull(adele_supports('a_feature_the_module_does_not_implement'));
    }

    /**
     * Adding an instance stores the participants list (an array from the form) as a CSV string.
     *
     * @covers ::adele_add_instance
     */
    public function test_add_instance_implodes_array_participantslist(): void {
        global $DB;
        $id = adele_add_instance($this->make_instance(['participantslist' => [3, 7]]));
        $rec = $DB->get_record('adele', ['id' => $id]);
        $this->assertEquals('3,7', $rec->participantslist);
        $this->assertNotEmpty($rec->timecreated);
    }

    /**
     * Adding an instance from a restore (participantslist already a CSV string) must not crash.
     *
     * @covers ::adele_add_instance
     */
    public function test_add_instance_keeps_string_participantslist(): void {
        global $DB;
        $id = adele_add_instance($this->make_instance(['participantslist' => '3,7']));
        $this->assertEquals('3,7', $DB->get_record('adele', ['id' => $id])->participantslist);
    }

    /**
     * Updating an instance persists the new values (and imploded participants list).
     *
     * @covers ::adele_update_instance
     */
    public function test_update_instance(): void {
        global $DB;
        $id = adele_add_instance($this->make_instance());
        $update = $this->make_instance(['name' => 'Renamed', 'participantslist' => [9]]);
        $update->instance = $id;
        $this->assertTrue(adele_update_instance($update));
        $rec = $DB->get_record('adele', ['id' => $id]);
        $this->assertEquals('Renamed', $rec->name);
        $this->assertEquals('9', $rec->participantslist);
    }

    /**
     * Deleting an existing instance removes it and returns true; deleting a missing one returns false.
     *
     * @covers ::adele_delete_instance
     */
    public function test_delete_instance(): void {
        global $DB;
        $id = adele_add_instance($this->make_instance());
        $this->assertTrue(adele_delete_instance($id));
        $this->assertFalse($DB->record_exists('adele', ['id' => $id]));
        $this->assertFalse(adele_delete_instance($id));
    }
}

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
 * This file contains the backup activity for the assign module
 *
 * @package mod_adele
 * @copyright 2024 Wunderbyte GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_adele;

use advanced_testcase;

defined('MOODLE_INTERNAL') || die();
global $CFG;

require_once($CFG->dirroot . '/mod/adele/lib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/controller/backup_controller.class.php');
require_once($CFG->dirroot . '/backup/controller/restore_controller.class.php');

/**
 * Unit test for backup and restore functionality of mod_adele.
 */
final class backup_restore_test extends advanced_testcase {
    /**
     * Sets up the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
    }

    /**
     * Tests backup and restore of the adele activity module.
     * @covers \mod_adele\backup_restore
     */
    public function test_backup_and_restore(): void {
        global $DB, $USER;

        // Create a course and an instance of the Adele module.
        $course = $this->getDataGenerator()->create_course();
        $adele = $this->getDataGenerator()->create_module('adele', [
            'course' => $course->id,
            'name' => 'Test Adele Activity',
            'learningpathid' => 1,
            'view' => 1,
            'userlist' => 0,
            'participantslist' => '0',
            'intro' => 'Test intro',
            'introformat' => FORMAT_HTML,
        ]);

        // Verify the instance was created.
        $this->assertNotEmpty($DB->get_record('adele', ['id' => $adele->id]));

        // Perform a backup of the course.
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );

        $this->assertNotNull($bc, 'Backup controller is null.');
        $this->assertNotNull($bc->get_plan(), 'Backup plan is null.');

        $bc->execute_plan();
        $backupid = $bc->get_backupid();
        $backupfilepath = $bc->get_results()['backup_destination']->get_filename();
        $bc->destroy();

        // Prepare for restore.
        $restorecourse = $this->getDataGenerator()->create_course();
        $rc = new \restore_controller(
            $backupid,
            $restorecourse->id,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
    }
}

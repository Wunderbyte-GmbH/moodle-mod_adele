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
use context_module;
use mod_adele\local\view_mode;

/**
 * Which view of an embedded learning path a role gets (#39).
 *
 * @package     mod_adele
 * @category    test
 * @copyright   2026 Wunderbyte GmbH
 * @copyright   2026 Ralf Erlebach
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \mod_adele\local\view_mode
 */
final class view_mode_test extends advanced_testcase {
    /**
     * The view of one role in an activity.
     *
     * @param string $role The role's short name.
     * @return array
     */
    private function mode_of(string $role): array {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $activity = $gen->create_module('adele', [
            'course' => $course->id,
            'learningpathid' => 0,
            'participantslist' => '0',
            'userlist' => 1,
            'view' => 1,
        ]);
        $this->setUser($gen->create_and_enrol($course, $role));
        return view_mode::for_context(context_module::instance($activity->cmid));
    }

    /**
     * The teacher with editing rights: the teacher's view, with its controls.
     */
    public function test_editing_teacher_gets_the_teacher_view(): void {
        $this->resetAfterTest();
        $this->assertSame(['view' => 'teacher', 'readonly' => false], $this->mode_of('editingteacher'));
    }

    /**
     * The teacher without editing rights: the teacher's view as well - it
     * used to be the participant's - but read-only.
     */
    public function test_nonediting_teacher_gets_the_teacher_view_read_only(): void {
        $this->resetAfterTest();
        $this->assertSame(['view' => 'teacher', 'readonly' => true], $this->mode_of('teacher'));
    }

    /**
     * A participant: the participant's view.
     */
    public function test_participant_gets_the_participant_view(): void {
        $this->resetAfterTest();
        $this->assertSame(['view' => 'student', 'readonly' => false], $this->mode_of('student'));
    }

    /**
     * The right to CREATE the activity says nothing about its learners: with
     * mod/adele:addinstance alone the view stays the participant's.
     */
    public function test_addinstance_alone_is_not_a_teacher(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $activity = $gen->create_module('adele', [
            'course' => $course->id,
            'learningpathid' => 0,
            'participantslist' => '0',
            'userlist' => 1,
            'view' => 1,
        ]);
        $context = context_module::instance($activity->cmid);
        $roleid = $gen->create_role();
        assign_capability('mod/adele:addinstance', CAP_ALLOW, $roleid, \context_system::instance()->id);
        assign_capability('mod/adele:readinstance', CAP_ALLOW, $roleid, \context_system::instance()->id);
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $course->id, $roleid);
        $this->setUser($user);
        $this->assertSame(['view' => 'student', 'readonly' => false], view_mode::for_context($context));
    }
}

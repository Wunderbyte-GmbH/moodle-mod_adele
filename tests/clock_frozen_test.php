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

/**
 * Issue #36: whether an enrolment counts as active is decided by core's clock.
 *
 * host_policy treats a course enrolment as a participant source only while it
 * is active (timestart / timeend). With core's clock that window can be
 * checked at an exact second instead of by waiting for it to pass.
 *
 * @package    mod_adele
 * @category   test
 * @copyright  2026 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_adele\local\host_policy
 */
final class clock_frozen_test extends \advanced_testcase {
    /**
     * An enrolment counts until its end time and no longer.
     *
     * @return void
     */
    public function test_active_enrolment_follows_the_clock(): void {
        $this->resetAfterTest();

        $start = 1893456000; // 2030-01-01 00:00:00 UTC.
        $end = $start + DAYSECS;
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student', 'manual', $start, $end);

        $check = new \ReflectionMethod(\mod_adele\local\host_policy::class, 'has_foreign_enrolment');
        $check->setAccessible(true);

        $cases = [
            'before it starts' => [$start - 1, false],
            'on its first second' => [$start, true],
            'one second before it ends' => [$end - 1, true],
            'on its end second' => [$end, false],
        ];
        foreach ($cases as $label => [$now, $expected]) {
            $this->mock_clock_with_frozen($now);
            $this->assertSame($expected, $check->invoke(null, (int) $user->id, [(int) $course->id]), $label);
        }
    }
}

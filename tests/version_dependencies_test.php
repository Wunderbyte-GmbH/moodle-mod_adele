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
 * Dependency floors must match the API surface this plugin relies on.
 *
 * PR #34 review: host_policy replaced the mirror-table design, but the
 * declared floors still admitted a local_adele whose enrol_state reads the
 * abandoned mirror - installs cleanly, never fatals, silently reconciles
 * from a permanently stale index. The floors are the only guard against
 * that pairing persisting beyond an upgrade window.
 *
 * @package    mod_adele
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_adele;

use advanced_testcase;

/**
 * Version dependency floors (PR #34 review).
 *
 * @package    mod_adele
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class version_dependencies_test extends advanced_testcase {
    /**
     * The floors must demand the siblings that actually provide / consume the
     * host_policy API: local_adele >= 2026082902 (enrol_state routes through
     * host_policy instead of the dropped mirror table) and enrol_adele >=
     * 2026082903 (reconciler asks for candidates and entitlements).
     *
     * @return void
     */
    public function test_dependency_floors_match_the_host_policy_api(): void {
        $plugin = new \stdClass();
        require(__DIR__ . '/../version.php');
        $this->assertGreaterThanOrEqual(
            2026082902,
            $plugin->dependencies['local_adele'],
            'A local_adele below 2026082902 still reads the abandoned host-course mirror table.'
        );
        $this->assertGreaterThanOrEqual(
            2026082903,
            $plugin->dependencies['enrol_adele'],
            'The enrol_adele below 2026082903 lacks the host sweep that host_policy exists to serve.'
        );
    }
}

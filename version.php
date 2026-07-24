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

/**
 * Plugin version and other meta-data are defined here.
 *
 * @package     mod_adele
 * @copyright   2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'mod_adele';
$plugin->supported = [405, 502];
$plugin->release = '0.1.13';
$plugin->version = 2026072402;
$plugin->requires = 2024100700;
$plugin->maturity = MATURITY_ALPHA;
// Fix G.2 (Session 003, Teil 1): mod_adele's code genuinely calls
// enrol_adele\local\reconciler (reconcile_host_user(), purge_all_host_user()
// etc.) — this was a real, undeclared dependency. Completes the target
// dependency graph already decided in G-Q1: local_adele (base) <-
// enrol_adele <- mod_adele. Deliberately does NOT create a cycle: enrol_adele
// does not (and per G-Q1 must not) declare a dependency back on mod_adele.
// local_adele bound raised (Session 003, G.2 full solution): lib.php now
// calls enrol_state::sync_host_course_index()/remove_host_course_index(),
// which only exist from this local_adele version onward.
$plugin->dependencies = [
    'local_adele' => 2026072404,
    'enrol_adele' => 2026072305,
];

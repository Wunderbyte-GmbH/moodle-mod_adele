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
 * Seed a browser-test fixture for mod_adele.
 *
 * Creates a learning path, a node course and a host course carrying an ADELE
 * activity, then prints shell assignments so the caller can source them or
 * feed them into the job environment:
 *
 *     php mod/adele/tests/playwright/seed.php > /tmp/seed.env
 *     . /tmp/seed.env
 *
 * DESTRUCTIVE. It resets the admin password so the browser suite has a known
 * credential, and is meant for a throwaway CI site, never for an installation
 * anyone depends on. It refuses to run unless ADELE_SEED_I_KNOW=1 is set.
 *
 * @package     mod_adele
 * @copyright   2026 Wunderbyte GmbH
 * @copyright   2026 Ralf Erlebach
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->dirroot . '/user/lib.php');
// The data generators live under lib/testing and are not autoloaded outside
// the PHPUnit bootstrap; this script runs against a normal site.
require_once($CFG->libdir . '/testing/generator/lib.php');
require_once($CFG->libdir . '/testing/generator/data_generator.php');

if (getenv('ADELE_SEED_I_KNOW') !== '1') {
    cli_error(
        "Refusing to run: this script resets the admin password and writes fixture data.\n" .
        "Set ADELE_SEED_I_KNOW=1 to confirm this is a throwaway site."
    );
}

$adminpassword = getenv('ADELE_ADMIN_PASSWORD') ?: 'Playwright!23';
$suffix = time();
$lpname = 'Playwright-Lernpfad ' . $suffix;
$courseshortname = 'PW' . $suffix;

// Moodle's default is to mark the session cookie Secure. A browser refuses
// to store such a cookie over plain http, so no session is established. The
// browser suite runs against http://127.0.0.1, so the flag has to go.
if (!empty($CFG->cookiesecure)) {
    set_config('cookiesecure', 0);
}

// A site served over plain HTTP cannot keep a Secure session cookie. Moodle's
// installer defaults cookiesecure to on, the browser then drops the cookie,
// no session exists, and the login token check fails — so Moodle answers a
// perfectly valid login with "Invalid login, please try again". The failure
// names the wrong cause, which is why it belongs here rather than in a
// workaround inside the browser suite.
if (strpos($CFG->wwwroot, 'https://') !== 0) {
    set_config('cookiesecure', 0);
}

// A known admin credential for the browser suite.
$admin = get_admin();
$admin->password = hash_internal_user_password($adminpassword);
$DB->set_field('user', 'password', $admin->password, ['id' => $admin->id]);

$generator = new testing_data_generator();

$course = $generator->create_course([
    'shortname' => $courseshortname,
    'fullname' => 'Playwright-Zielkurs ' . $suffix,
]);

$json = [
    'tree' => [
        'nodes' => [
            [
                'id' => 'dndnode_1',
                'type' => 'courseNode',
                'parentCourse' => ['starting_node'],
                'data' => ['course_node_id' => [(int) $course->id]],
            ],
        ],
        'edges' => [],
    ],
];

$lpid = (int) $DB->insert_record('local_adele_learning_paths', (object) [
    'name' => $lpname,
    'description' => '',
    'timecreated' => time(),
    'timemodified' => time(),
    'createdby' => (int) $admin->id,
    'json' => json_encode($json),
]);

// The host course and the activity that embeds the learning path in it.
$hostcourse = $generator->create_course([
    'shortname' => $courseshortname . 'H',
    'fullname' => 'Playwright-Hostkurs ' . $suffix,
]);

$module = $generator->create_module('adele', [
    'course' => (int) $hostcourse->id,
    'name' => 'Playwright-Lernpfadaktivität',
    'learningpathid' => $lpid,
    'participantslist' => '1',
    'hostenrolmentmode' => 'visible',
    'userlist' => 1,
    'view' => 1,
]);

$cm = get_coursemodule_from_instance('adele', (int) $module->id, (int) $hostcourse->id, false, MUST_EXIST);

printf("export ADELE_BASE_URL='%s'\n", $CFG->wwwroot);
printf("export ADELE_ADMIN_USER='%s'\n", $admin->username);
printf("export ADELE_ADMIN_PASSWORD='%s'\n", $adminpassword);
printf("export ADELE_LP_NAME='%s'\n", $lpname);
printf("export ADELE_LP_ID='%d'\n", $lpid);
printf("export ADELE_COURSE_SHORTNAME='%s'\n", $courseshortname);
printf("export ADELE_HOST_COURSE_ID='%d'\n", (int) $hostcourse->id);
printf("export ADELE_CMID='%d'\n", (int) $cm->id);

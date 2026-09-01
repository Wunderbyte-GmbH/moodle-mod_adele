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
require_once($CFG->dirroot . '/course/lib.php');
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

// The plugin's own strings are German because mod_adele ships a German
// language pack; the specs assert on those. Moodle CORE strings stay in
// whatever language the site uses - the specs address core elements by form
// field name instead, so no core language pack has to be downloaded in CI.
// One dependency less, and one download less per run.

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

$fixturepassword = 'Playwright!23';

/**
 * Create or reuse a user with a fixed username.
 *
 * Fixed rather than suffixed: the participant assertions look these names up
 * exactly, and the spec forbids randomising anything an assertion depends on.
 *
 * @param testing_data_generator $generator The generator.
 * @param string $username The fixed username.
 * @param string $firstname First name.
 * @param string $lastname Last name.
 * @param string $password Password to set.
 * @return object The user record.
 */
function adele_pw_user($generator, string $username, string $firstname, string $lastname, string $password) {
    global $DB;
    $existing = $DB->get_record('user', ['username' => $username]);
    if ($existing) {
        $DB->set_field('user', 'password', hash_internal_user_password($password), ['id' => $existing->id]);
        return $existing;
    }
    return $generator->create_user([
        'username' => $username,
        'firstname' => $firstname,
        'lastname' => $lastname,
        'email' => $username . '@example.invalid',
        'password' => $password,
    ]);
}

/**
 * Create or reuse a course with a fixed shortname.
 *
 * @param testing_data_generator $generator The generator.
 * @param string $shortname Fixed shortname.
 * @param string $fullname Full name.
 * @return object The course record.
 */
function adele_pw_course($generator, string $shortname, string $fullname) {
    global $DB;
    $existing = $DB->get_record('course', ['shortname' => $shortname]);
    return $existing ?: $generator->create_course(['shortname' => $shortname, 'fullname' => $fullname]);
}

$lifecyclehost = adele_pw_course($generator, 'PWMODHOST', 'PW mod_adele Hostkurs');
$lifecyclestart = adele_pw_course($generator, 'PWMODSTART', 'PW mod_adele Startnode-Kurs');

$startuser1 = adele_pw_user($generator, 'pw_startnode_01', 'Playwright', 'Startnode Eins', $fixturepassword);
$startuser2 = adele_pw_user($generator, 'pw_startnode_02', 'Playwright', 'Startnode Zwei', $fixturepassword);
$controluser = adele_pw_user($generator, 'pw_control_01', 'Playwright', 'Kontrolle', $fixturepassword);

// Only the two positive users, only into the STARTING NODE course. The
// negative control gets nothing - it is what proves the transfer is not
// simply enrolling everyone.
$generator->enrol_user((int) $startuser1->id, (int) $lifecyclestart->id, 'student');
$generator->enrol_user((int) $startuser2->id, (int) $lifecyclestart->id, 'student');

// Any ADELE enrolment instance left in the fixture host course by an earlier
// run is removed outright.
//
// Draining the queues above only SUSPENDS those enrolments — that is the
// product's suspend-not-delete rule, and it is correct at runtime. For a
// fixture it is not enough: a suspended participant still appears in the
// participants list, so the spec's very first assertion ("nobody is in the
// host course yet") would fail on the leftovers of the previous run rather
// than on anything the current run did. The nightly reconcile removes such
// unembedded host instances anyway; this does the same thing now, through the
// plugin's own delete_instance() so that its clean-up runs too.
$hostplugin = enrol_get_plugin('adele');
if ($hostplugin) {
    foreach ($DB->get_records('enrol', ['enrol' => 'adele', 'courseid' => $lifecyclehost->id]) as $leftover) {
        $hostplugin->delete_instance($leftover);
    }
}

$lifecycletitle = 'PW mod_adele Startnode Lifecycle';
$lifecyclejson = [
    'tree' => [
        'nodes' => [
            [
                'id' => 'dndnode_1',
                'type' => 'courseNode',
                'parentCourse' => ['starting_node'],
                'data' => ['course_node_id' => [(int) $lifecyclestart->id]],
            ],
        ],
        'edges' => [],
    ],
];
$existingpath = $DB->get_record('local_adele_learning_paths', ['name' => $lifecycletitle]);
if ($existingpath) {
    $lifecyclepathid = (int) $existingpath->id;
    $DB->set_field('local_adele_learning_paths', 'json', json_encode($lifecyclejson), ['id' => $lifecyclepathid]);
} else {
    $lifecyclepathid = (int) $DB->insert_record('local_adele_learning_paths', (object) [
        'name' => $lifecycletitle,
        'description' => 'Playwright-Fixture',
        'timecreated' => time(),
        'timemodified' => time(),
        'createdby' => (int) $admin->id,
        'visibility' => 1,
        'json' => json_encode($lifecyclejson),
    ]);
}

// Leftovers from an earlier run would make the "before" assertion pass or
// fail for the wrong reason, so any mod_adele activity for this path in this
// course is removed - through the plugin's own lifecycle, not by deleting
// rows, so its cleanup runs too.
foreach ($DB->get_records('adele', ['course' => $lifecyclehost->id, 'learningpathid' => $lifecyclepathid]) as $stale) {
    $stalecm = get_coursemodule_from_instance('adele', (int) $stale->id, (int) $lifecyclehost->id);
    if ($stalecm) {
        course_delete_module((int) $stalecm->id);
    } else {
        $DB->delete_records('adele', ['id' => $stale->id]);
    }
}

// Deleting the activity only QUEUES the clean-up of the host enrolments it
// caused, so a fixture that stops here leaves the previous run's enrolments
// behind — and the spec's very first assertion ("nobody is in the host course
// yet") would then fail for a reason that has nothing to do with the run under
// test. The queue is therefore drained here, exactly as the spec drains it
// after its own deletion.
// Restricted to ADELE's own task class. Draining the whole queue would also
// run unrelated core tasks that fail in a throwaway fixture environment (a
// login notification with no mail configuration, for one) and abort the seed
// for a reason that has nothing to do with ADELE.
// Two classes in order: course_delete_module() only QUEUES the deletion, and
// adele_delete_instance() — which queues ADELE's clean-up — runs inside that
// core task. Draining ADELE's queue first would find it empty.
foreach (
    [
        '\\core_course\\task\\course_delete_modules',
        '\\enrol_adele\\task\\reconcile_host_embedding_adhoc',
    ] as $adeletask) {
    if (!class_exists($adeletask)) {
        continue;
    }
    while (($queued = \core\task\manager::get_next_adhoc_task(time() + DAYSECS, true, $adeletask)) !== null) {
        try {
            $queued->execute();
            \core\task\manager::adhoc_task_complete($queued);
        } catch (\Throwable $e) {
            \core\task\manager::adhoc_task_failed($queued);
            cli_error('Seed could not drain the ad-hoc queue: ' . $e->getMessage());
        }
    }
}

printf("export ADELE_BASE_URL='%s'\n", $CFG->wwwroot);
printf("export ADELE_ADMIN_USER='%s'\n", $admin->username);
printf("export ADELE_ADMIN_PASSWORD='%s'\n", $adminpassword);
printf("export ADELE_LP_NAME='%s'\n", $lpname);
printf("export ADELE_LP_ID='%d'\n", $lpid);
printf("export ADELE_COURSE_SHORTNAME='%s'\n", $courseshortname);
printf("export ADELE_HOST_COURSE_ID='%d'\n", (int) $hostcourse->id);
printf("export ADELE_CMID='%d'\n", (int) $cm->id);
printf("export ADELE_FIXTURE_PASSWORD='%s'\n", $fixturepassword);
printf("export ADELE_MOD_HOST_COURSE_ID='%d'\n", (int) $lifecyclehost->id);
printf("export ADELE_MOD_HOST_COURSE_URL='%s'\n", $CFG->wwwroot . '/course/view.php?id=' . (int) $lifecyclehost->id);
printf("export ADELE_MOD_STARTNODE_COURSE_ID='%d'\n", (int) $lifecyclestart->id);
printf("export ADELE_MOD_PATH_ID='%d'\n", $lifecyclepathid);
printf("export ADELE_MOD_PATH_TITLE='%s'\n", $lifecycletitle);
printf("export ADELE_MOD_STARTNODE_USER01='%s'\n", $startuser1->username);
printf("export ADELE_MOD_STARTNODE_USER02='%s'\n", $startuser2->username);
printf("export ADELE_MOD_CONTROL_USER='%s'\n", $controluser->username);
// The Moodle root, so a spec can run the ad-hoc task queue explicitly instead
// of waiting for cron. Waiting is forbidden by the test contract, and would be
// wrong anyway: a timeout proves nothing about whether the task ever ran.
printf("export ADELE_MOODLE_ROOT='%s'\n", $CFG->dirroot);

// Self-check: every variable the suite reads must actually have been printed.
//
// This exists because it already went wrong once in the sibling plugin. A seed
// that had lost part of its fixture block still ran, still printed the basic
// variables and still exited zero; the failure surfaced much later as browser
// tests complaining about unset environment variables. Silence is the wrong
// answer when half the fixture is missing.
$expected = [
    'ADELE_BASE_URL', 'ADELE_ADMIN_USER', 'ADELE_ADMIN_PASSWORD',
    'ADELE_LP_NAME', 'ADELE_LP_ID', 'ADELE_COURSE_SHORTNAME',
    'ADELE_HOST_COURSE_ID', 'ADELE_CMID', 'ADELE_FIXTURE_PASSWORD',
    'ADELE_MOD_HOST_COURSE_ID', 'ADELE_MOD_HOST_COURSE_URL', 'ADELE_MOD_STARTNODE_COURSE_ID',
    'ADELE_MOD_PATH_ID', 'ADELE_MOD_PATH_TITLE', 'ADELE_MOD_STARTNODE_USER01',
    'ADELE_MOD_STARTNODE_USER02', 'ADELE_MOD_CONTROL_USER', 'ADELE_MOODLE_ROOT',
];
$printed = [];
foreach (file(__FILE__) as $line) {
    if (preg_match('/^printf\\("export (ADELE_[A-Z0-9_]+)=/', $line, $m)) {
        $printed[] = $m[1];
    }
}
$missing = array_diff($expected, $printed);
if ($missing) {
    cli_error(
        'Seed is incomplete: it does not print ' . implode(', ', $missing) . '. ' .
        'The browser suite reads these, so a partial fixture would fail far from its cause.'
    );
}

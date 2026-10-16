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
 * withdrawn-settings - negative controls for settings changed AFTER they took
 * effect (E2E issue, addendum of 2026-10-13, A-C).
 *
 * host-course-participants.spec.ts and learner-data.spec.ts prove each
 * setting as it is when an embedding is CREATED. Whether taking a setting
 * back actually takes back what it granted is a different question, and the
 * one asked here. Every chain follows the same arc:
 *
 *     starting point -> the setting works (shown AND real access)
 *     -> the teacher changes the same activity in Moodle's own form, saves
 *     -> reconcile (the ad-hoc queue, drained) -> a fresh session
 *     -> the change is visible -> real access follows it
 *     -> somebody the change does not concern keeps what they had
 *     -> back again
 *
 *   A  (H3)     host enrolment mode of an embedding with active enrolments:
 *               visible -> hidden -> none -> visible, for the sources
 *               "starting node" and "any course of the path"; a person with
 *               an enrolment of their own in the host course keeps it.
 *               "none" suspends first and removes after the retention
 *               period; "hidden" is never removed (decision of 2026-10-13).
 *   B  (H2)     a participant source removed from an embedding: the person
 *               who came only through it loses what it gave - when the form
 *               is saved (decision of 2026-10-13) -, the person of the other
 *               source keeps theirs, and nothing ADELE did not create is
 *               touched; restored, it gives it back.
 *   C  (P1/P2)  "Participant results" taken back from "all" to "only own":
 *               the other learner is gone from the page AND from what the
 *               server hands out, also to a page opened before the change
 *               and to a request that worked before; back to "all".
 *
 * Only the browser and Moodle's form change settings here; the server is
 * read, never written, by the test (addendum, DoD).
 *
 * @copyright   2026 Wunderbyte GmbH
 * @copyright   2026 Ralf Erlebach
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { execFileSync } from 'node:child_process';
import { test, expect, Page } from '@playwright/test';
import { env, loginAs } from '../support/env';
import {
  callService,
  completeCourse,
  courseAccess,
  drainTaskQueue,
  enrolmentState,
  expectCourseClosed,
  expectCourseOpenAfterTasks,
  fixture,
  fixtureCourse,
  fixturePassword,
  nodeStatus,
  participantList,
  referencePath,
  reseedFixtures,
  resetServerTime,
  runCompletion,
  runLabel,
  serverTimeEnv,
  setServerTime,
} from '../support/fixtures';
import {
  editEmbedding,
  embeddingCmid,
  embedWith,
  MODE_HIDDEN,
  MODE_NONE,
  MODE_VISIBLE,
  onPath,
  participantRow,
  RESULTS_ALL,
  RESULTS_OWN,
  SOURCE_ANY,
  SOURCE_START,
  SOURCE_THIS,
  storedEmbedding,
} from '../support/host';

/** The mode values the form stores. */
const STORED_MODE: Record<string, string> = {
  [MODE_VISIBLE]: 'visible',
  [MODE_HIDDEN]: 'hidden',
  [MODE_NONE]: 'none',
};

/**
 * How a person is listed on a course's participants page: "absent", or the
 * row's status text (Active / Suspended, as Moodle words it).
 *
 * @param page The page, logged in as admin.
 * @param courseid The course.
 * @param fullname The person's full name.
 */
async function listedAs(page: Page, courseid: string, fullname: string): Promise<string> {
  const row = await participantRow(page, courseid, fullname);
  if (!(await row.count())) {
    return 'absent';
  }
  const text = (await row.first().innerText()).replace(/\s+/g, ' ');
  // A person with two enrolments shows both statuses; Moodle lists them in
  // the status column, and both words then appear.
  const states = ['Active', 'Suspended', 'Not current'].filter((word) => text.includes(word));
  return states.join(' + ') || text;
}

/**
 * The enrolments of a person in a course, by method, read on the server:
 * e.g. "adele:suspended manual:active".
 *
 * @param username The person.
 * @param courseid The course.
 */
function enrolmentsOf(username: string, courseid: string): string {
  return enrolmentState(username, courseid).methods
    .map((method) => `${method.enrol}:${method.userstatus === 0 ? 'active' : 'suspended'}`)
    .sort().join(' ') || 'none';
}

// Every file starts from the seeded state, whatever ran before it
// (support/fixtures: reseedFixtures; E2E issue, section 3B).
test.beforeAll(() => reseedFixtures());

/**
 * The course opens for this person - through whatever enrolment they hold.
 *
 * expectCourseOpenAfterTasks insists on ADELE as the reason; here the point
 * is the opposite: an enrolment ADELE did not make must keep working while
 * ADELE takes its own back.
 *
 * @param page The page, logged in as the person.
 * @param courseid The course.
 * @param because What this proves.
 */
async function expectCourseOpenAnyEnrolment(page: Page, courseid: string, because: string): Promise<void> {
  await expect.poll(async () => { drainTaskQueue(); return courseAccess(page, courseid); },
    { message: because, timeout: 60_000, intervals: [1000, 2000, 5000] }).toBe('open');
}

/**
 * Run enrol_adele's nightly reconcile once, on the server's (test) clock.
 * Its pass 7 is what finds subscriptions no embedding carries any more.
 */
function runNightlyReconcile(): void {
  execFileSync('php', ['-d', 'max_input_vars=5000', 'admin/cli/scheduled_task.php',
    '--execute=\\enrol_adele\\task\\reconcile_task'],
  { cwd: fixture('ADELE_MOODLE_ROOT'), encoding: 'utf8', env: { ...process.env, ...serverTimeEnv() } });
  drainTaskQueue();
}

/**
 * enrol_adele's retention period for suspended enrolments, in days, as this
 * site has it configured (enrol_adele/suspendedretention).
 */
function retentionDays(): number {
  const out = execFileSync('php', ['-r', `
    define('CLI_SCRIPT', true);
    require('config.php');
    echo (int) get_config('enrol_adele', 'suspendedretention');`],
  { cwd: fixture('ADELE_MOODLE_ROOT'), encoding: 'utf8' });
  return Number(out.trim().split('\n').pop());
}

/**
 * Let the retention period pass on the server's test clock: one day past it,
 * the nightly reconcile runs (its pass 8 removes what has been suspended for
 * longer, unless it is suspended by design). Removals that run queues with a
 * delay are run while the clock is still ahead; then the clock goes back.
 */
function afterRetentionPeriod(): void {
  const days = retentionDays();
  expect(days, 'the site must have a retention period, or nothing is ever removed').toBeGreaterThan(0);
  const ahead = Date.now() / 1000 + (days + 1) * 86400;
  try {
    setServerTime(ahead);
    runNightlyReconcile();
    setServerTime(ahead + 400);
    drainTaskQueue();
  } finally {
    resetServerTime();
  }
}

for (const variant of [
  {
    label: 'source "starting node"',
    host: 'ADELE_FIXTURE_HOST_COURSE_10',
    source: SOURCE_START,
    carried: 'ADELE_FIXTURE_ENTRY_LEARNER',
  },
  {
    label: 'source "any course of the path"',
    host: 'ADELE_FIXTURE_HOST_COURSE_11',
    source: SOURCE_ANY,
    carried: 'ADELE_FIXTURE_NODE_LEARNER',
  },
]) {
  test.describe(`ADELE-E2E-H3 — the host enrolment mode taken back, ${variant.label}`, () => {
    test('visible -> hidden -> none -> visible: access follows, an enrolment of one\'s own stays',
      { tag: ['@story:H3', '@withdrawal'] }, async ({ page }) => {
        const hostcourse = fixture(variant.host);
        const carried = fixture(variant.carried);
        const carriedname = fixture(`${variant.carried}_NAME`);
        // In the host course by an enrolment of their own AND reached by the
        // source: whatever ADELE does to its enrolment must leave theirs.
        const dual = fixture('ADELE_FIXTURE_HOST_DUAL');
        const dualname = fixture('ADELE_FIXTURE_HOST_DUAL_NAME');
        const activity = runLabel(`E2E Modus zurückgenommen ${variant.label}`);
        let cmid = '';

        await test.step('precondition: the carried person is not in the host course, the dual one is', async () => {
          expect(enrolmentsOf(carried, hostcourse), `${carried} before`).toBe('none');
          expect(enrolmentsOf(dual, hostcourse), `${dual} before`).toBe('manual:active');
        });

        await test.step('the teacher embeds the path with mode "visible"', async () => {
          await loginAs(page, env.adminUser, env.adminPassword);
          await embedWith(page, hostcourse, activity, [variant.source], MODE_VISIBLE);
          cmid = await embeddingCmid(page, hostcourse, activity);
          expect(storedEmbedding(cmid).hostenrolmentmode).toBe('visible');
          drainTaskQueue();
        });

        await test.step('"visible" works: listed as active, and the host course really opens', async () => {
          await expect.poll(async () => { drainTaskQueue(); return listedAs(page, hostcourse, carriedname); },
            { message: 'the carried person must be listed as active', timeout: 60_000 }).toBe('Active');
          await loginAs(page, carried, fixturePassword());
          await expectCourseOpenAfterTasks(page, hostcourse, '"visible" must open the host course');
        });

        const changeTo = async (mode: string): Promise<void> => {
          await test.step(`the teacher changes the same activity to "${STORED_MODE[mode]}" and saves`, async () => {
            await loginAs(page, env.adminUser, env.adminPassword);
            await editEmbedding(page, cmid, { mode });
            expect(storedEmbedding(cmid).hostenrolmentmode, 'the form stored the new mode').toBe(STORED_MODE[mode]);
            drainTaskQueue();
          });
        };
        const expectCarried = async (label: string, listed: string, enrolled: string, open: boolean): Promise<void> => {
          await test.step(`${label}: listed as ${listed}; on the server ${enrolled}`, async () => {
            await loginAs(page, env.adminUser, env.adminPassword);
            await expect.poll(async () => { drainTaskQueue(); return listedAs(page, hostcourse, carriedname); },
              { message: 'the participants list follows the mode', timeout: 60_000 }).toBe(listed);
            expect(enrolmentsOf(carried, hostcourse), 'the enrolment ADELE manages').toBe(enrolled);
          });
          await test.step(`${label}: real access, in a fresh session`, async () => {
            await loginAs(page, carried, fixturePassword());
            if (open) {
              await expectCourseOpenAfterTasks(page, hostcourse, `${label}: the host course must open`);
            } else {
              await expectCourseClosed(page, hostcourse, `${label}: the host course must stay closed`);
            }
          });
        };
        const expectDualKeepsOwn = async (label: string): Promise<void> => {
          await test.step(`${label}: the dual person keeps the enrolment of their own, and access`, async () => {
            expect(enrolmentsOf(dual, hostcourse), 'their manual enrolment is untouched')
              .toMatch(/(^| )manual:active( |$)/);
            await loginAs(page, env.adminUser, env.adminPassword);
            expect(await listedAs(page, hostcourse, dualname), 'still listed, still active').toContain('Active');
            await loginAs(page, dual, fixturePassword());
            await expectCourseOpenAnyEnrolment(page, hostcourse, 'an enrolment ADELE did not make keeps the course open');
          });
        };

        await changeTo(MODE_HIDDEN);
        await expectCarried('"hidden"', 'Suspended', 'adele:suspended', false);
        await expectDualKeepsOwn('"hidden"');

        // Negative control for the retention rule below: a "hidden" record is
        // suspended by design and keeps the learner countable - the nightly
        // reconcile past the retention period must leave it alone.
        await test.step('"hidden", past the retention period: the record stays', async () => {
          afterRetentionPeriod();
        });
        await expectCarried('"hidden" after the retention period', 'Suspended', 'adele:suspended', false);

        // "none" (decision of 2026-10-13): suspend first, remove later.
        await changeTo(MODE_NONE);
        await expectCarried('"none"', 'Suspended', 'adele:suspended', false);
        await expectDualKeepsOwn('"none"');

        await test.step('"none", past the retention period: the nightly reconcile removes the record', async () => {
          afterRetentionPeriod();
        });
        await expectCarried('"none" after the retention period', 'absent', 'none', false);
        await test.step('"none" after the retention period: the dual person has their own enrolment only', async () => {
          expect(enrolmentsOf(dual, hostcourse)).toBe('manual:active');
        });
        await expectDualKeepsOwn('"none" after the retention period');

        await changeTo(MODE_VISIBLE);
        await expectCarried('back to "visible"', 'Active', 'adele:active', true);
        await expectDualKeepsOwn('back to "visible"');
      });
  });
}

test.describe('ADELE-E2E-H2 — a participant source taken back from an embedding', () => {
  test('"this course" removed: who came through it loses the path, the other source\'s person keeps theirs; restored: back',
    { tag: ['@story:H2', '@withdrawal'] }, async ({ page }) => {
      const hostcourse = fixture('ADELE_FIXTURE_HOST_COURSE_12');
      const entrycourse = fixtureCourse('T01');
      // A: in the host course by hand, on the path only through "this course".
      const a = fixture('ADELE_FIXTURE_HOST_SOURCE');
      // B: in the entry course by hand, in the host course only through
      // "starting node".
      const b = fixture('ADELE_FIXTURE_ENTRY_LEARNER');
      const bname = fixture('ADELE_FIXTURE_ENTRY_LEARNER_NAME');
      const pathid = referencePath.id;
      const activity = runLabel('E2E Quelle zurückgenommen');
      let cmid = '';
      let node2before = '';

      await test.step('precondition: A is on no path and not in the entry course; B is not in the host course', async () => {
        expect(onPath(a, pathid), 'A on the path').toBe(false);
        expect(enrolmentsOf(a, entrycourse), 'A in the entry course').toBe('none');
        expect(enrolmentsOf(a, hostcourse), 'A in the host course').toBe('manual:active');
        expect(enrolmentsOf(b, hostcourse), 'B in the host course').toBe('none');
      });

      await test.step('the teacher embeds the path with both sources', async () => {
        await loginAs(page, env.adminUser, env.adminPassword);
        await embedWith(page, hostcourse, activity, [SOURCE_THIS, SOURCE_START]);
        cmid = await embeddingCmid(page, hostcourse, activity);
        expect(storedEmbedding(cmid).participantslist.split(',').sort()).toEqual(['1', '2']);
        drainTaskQueue();
      });

      await test.step('both sources work: A reaches the entry course, B the host course', async () => {
        await loginAs(page, a, fixturePassword());
        await expectCourseOpenAfterTasks(page, entrycourse, '"this course" carries A onto the path');
        expect(onPath(a, pathid), 'A is on the path').toBe(true);
        // A learns: T01 completed - progress that lives in the course, not
        // in the subscription.
        await completeCourse(page, entrycourse);
        runCompletion();
        await expect.poll(() => { drainTaskQueue(); return nodeStatus(page, hostcourse, activity, 'dndnode_1'); },
          { message: "A's T01 node is completed", timeout: 90_000, intervals: [2_000, 5_000] }).toBe('completed');
        // Node 2 as it is now - in the fixture path a time window that is
        // over keeps it locked whatever A does, so its state is recorded,
        // not assumed.
        node2before = await nodeStatus(page, hostcourse, activity, 'dndnode_2');
        await loginAs(page, b, fixturePassword());
        await expectCourseOpenAfterTasks(page, hostcourse, '"starting node" carries B into the host course');
      });

      await test.step('the teacher removes "this course" from the same activity and saves', async () => {
        await loginAs(page, env.adminUser, env.adminPassword);
        await editEmbedding(page, cmid, { removeSources: [SOURCE_THIS] });
        expect(storedEmbedding(cmid).participantslist, 'the form stored one source').toBe('2');
        drainTaskQueue();
      });

      await test.step('A loses exactly what the removed source gave, when the form is saved', async () => {
        // Decision of 2026-10-13: a saved setting is the teacher's decision,
        // so it takes effect now - no nightly reconcile, no delay, no clock.
        // (The delay of enrol_adele #3 stays for enrolment events.)
        await expect.poll(() => { drainTaskQueue(); return onPath(a, pathid); },
          { message: 'A must no longer be on the path', timeout: 60_000 }).toBe(false);
        expect(enrolmentsOf(a, entrycourse), "A's ADELE enrolment in the entry course is gone too").toBe('none');
        await loginAs(page, a, fixturePassword());
        await expectCourseClosed(page, entrycourse, 'without the source, the entry course closes for A');
      });

      await test.step('nothing ADELE did not create is touched: A\'s own enrolment in the host course stays', async () => {
        expect(enrolmentsOf(a, hostcourse)).toBe('manual:active');
        await loginAs(page, a, fixturePassword());
        await expectCourseOpenAnyEnrolment(page, hostcourse, 'A is still a member of the host course');
      });

      await test.step('B, of the other source, keeps the host course - listed and with real access', async () => {
        await loginAs(page, env.adminUser, env.adminPassword);
        expect(await listedAs(page, hostcourse, bname)).toBe('Active');
        await loginAs(page, b, fixturePassword());
        await expectCourseOpenAfterTasks(page, hostcourse, 'removing one source must not withdraw the other');
      });

      await test.step('the teacher restores "this course": A is back on the path and in the entry course', async () => {
        await loginAs(page, env.adminUser, env.adminPassword);
        await editEmbedding(page, cmid, { addSources: [SOURCE_THIS] });
        expect(storedEmbedding(cmid).participantslist.split(',').sort()).toEqual(['1', '2']);
        drainTaskQueue();
        await loginAs(page, a, fixturePassword());
        await expectCourseOpenAfterTasks(page, entrycourse, 'restored, the source carries A again');
        expect(onPath(a, pathid)).toBe(true);
      });

      await test.step('the new subscription starts from what A has done in the courses: T01 completed, T02 as before', async () => {
        // Decision of 2026-10-15: progress follows course memberships and
        // results; a new subscription does not start from zero.
        await expect.poll(async () => {
          drainTaskQueue();
          return `${await nodeStatus(page, hostcourse, activity, 'dndnode_1')} ${await nodeStatus(page, hostcourse, activity, 'dndnode_2')}`;
        }, { message: 'the progress the courses hold', timeout: 90_000, intervals: [2_000, 5_000] })
          .toBe(`completed ${node2before}`);
      });
    });
});

/**
 * The context id of a course, read on the server.
 *
 * @param courseid The course.
 */
function courseContextId(courseid: string): string {
  return execFileSync('php', ['-r', `
    define('CLI_SCRIPT', true);
    require('config.php');
    echo context_course::instance((int) $argv[1])->id;`, '--', courseid],
  { cwd: fixture('ADELE_MOODLE_ROOT'), encoding: 'utf8' }).trim().split('\n').pop() as string;
}

test.describe('ADELE-E2E-H1 — an embedding deleted is cleanly gone; restored, it starts from the courses', () => {
  test('delete -> off the path and out of the courses at once; restore from the recycle bin -> back, with the progress the courses hold',
    { tag: ['@story:H1', '@withdrawal'] }, async ({ page }) => {
      const hostcourse = fixture('ADELE_FIXTURE_HOST_COURSE_13');
      const entrycourse = fixtureCourse('T01');
      const learner = fixture('ADELE_FIXTURE_HOST_DELETE');
      const pathid = referencePath.id;
      const activity = runLabel('E2E Einbettung gelöscht');
      let cmid = '';
      let node2before = '';

      await test.step('precondition: the learner is on no path and not in the entry course', async () => {
        expect(onPath(learner, pathid)).toBe(false);
        expect(enrolmentsOf(learner, entrycourse)).toBe('none');
        expect(enrolmentsOf(learner, hostcourse)).toBe('manual:active');
      });

      await test.step('the teacher embeds the path for the course\'s members; the learner completes T01', async () => {
        await loginAs(page, env.adminUser, env.adminPassword);
        await embedWith(page, hostcourse, activity, [SOURCE_THIS]);
        cmid = await embeddingCmid(page, hostcourse, activity);
        drainTaskQueue();
        await loginAs(page, learner, fixturePassword());
        await expectCourseOpenAfterTasks(page, entrycourse, 'the embedding carries the member into T01');
        await completeCourse(page, entrycourse);
        runCompletion();
        await expect.poll(() => { drainTaskQueue(); return nodeStatus(page, hostcourse, activity, 'dndnode_1'); },
          { message: 'T01 completed', timeout: 90_000, intervals: [2_000, 5_000] }).toBe('completed');
        node2before = await nodeStatus(page, hostcourse, activity, 'dndnode_2');
      });

      await test.step('the teacher deletes the activity', async () => {
        await loginAs(page, env.adminUser, env.adminPassword);
        // Moodle's own delete route with its confirmation page.
        await page.goto(`/course/mod.php?delete=${cmid}`);
        await page.getByRole('button', { name: /^(Yes|Ja|Delete|Löschen)$/ }).first().click();
        await expect(page).toHaveURL(/\/course\/view\.php/);
        drainTaskQueue();
      });

      await test.step('deleted is gone: off the path, the ADELE enrolment in T01 gone, T01 closed - at once', async () => {
        await expect.poll(() => { drainTaskQueue(); return onPath(learner, pathid); },
          { message: 'the learner must no longer be on the path', timeout: 60_000 }).toBe(false);
        expect(enrolmentsOf(learner, entrycourse), 'no enrolment left in T01').toBe('none');
        await loginAs(page, learner, fixturePassword());
        await expectCourseClosed(page, entrycourse, 'nothing justifies T01 any more');
        expect(enrolmentsOf(learner, hostcourse), 'the own membership of the course stays').toBe('manual:active');
      });

      await test.step('the teacher restores the activity from the recycle bin', async () => {
        await loginAs(page, env.adminUser, env.adminPassword);
        await page.goto(`/admin/tool/recyclebin/index.php?contextid=${courseContextId(hostcourse)}`);
        const row = page.locator('table tbody tr').filter({ hasText: activity });
        await expect(row, 'the deleted activity is in the recycle bin').toHaveCount(1);
        await row.getByRole('link', { name: /^(Restore|Wiederherstellen)$/ }).click();
        await page.goto(`/course/view.php?id=${hostcourse}`);
        await expect(page.getByRole('main').getByRole('link', { name: activity })).toBeVisible();
        drainTaskQueue();
      });

      await test.step('restored: back on the path, from the courses - T01 completed, T02 as before', async () => {
        await expect.poll(() => { drainTaskQueue(); return onPath(learner, pathid); },
          { message: 'the restored embedding must carry the member again', timeout: 60_000 }).toBe(true);
        await loginAs(page, learner, fixturePassword());
        await expect.poll(async () => {
          drainTaskQueue();
          return `${await nodeStatus(page, hostcourse, activity, 'dndnode_1')} ${await nodeStatus(page, hostcourse, activity, 'dndnode_2')}`;
        }, { message: 'progress read from the courses again', timeout: 90_000, intervals: [2_000, 5_000] })
          .toBe(`completed ${node2before}`);
      });
    });
});

const ALPHA = 'Privacy Alpha';
const BETA = 'Privacy Beta';

/**
 * Which of the two learners a person is shown in the switchable embedding.
 *
 * @param page The page, logged in.
 */
async function shownLearners(page: Page): Promise<string> {
  await page.goto(`/mod/adele/view.php?id=${fixture('ADELE_FIXTURE_P_CM_SWITCHABLE')}`);
  const table = page.locator('#adele-userlist-table');
  await expect(table.locator('tbody tr').first()).toBeVisible({ timeout: 60_000 });
  await page.waitForLoadState('networkidle');
  return learnersIn(await participantList(page));
}

/**
 * The two learners' names among rows or names, sorted, "neither" if none.
 *
 * @param rows Rows or names.
 */
function learnersIn(rows: string[]): string {
  return [ALPHA, BETA].filter((name) => rows.some((row) => row.startsWith(name))).join(', ') || 'neither';
}

/**
 * The roster the server hands out through the switchable embedding.
 *
 * @param page The page, logged in.
 * @param userid The (untrusted) userid parameter.
 */
async function rosterThroughSwitchable(page: Page, userid = 0): Promise<string> {
  const answer = await callService(page, 'local_adele_get_user_path_relations', {
    userid,
    learningpathid: Number(fixture('ADELE_FIXTURE_PATH_P')),
    contextid: Number(fixture('ADELE_FIXTURE_P_CM_SWITCHABLE_CONTEXT')),
  });
  if (answer.error) {
    return `denied (${answer.error})`;
  }
  return learnersIn((answer.data as { lastname: string }[]).map((row) => row.lastname));
}

test.describe('ADELE-E2E-P1/P2 — "Participant results" taken back from "all" to "only own"', () => {
  test('the other learner disappears from page and server; own data and the teacher stay; back to "all"',
    { tag: ['@story:P1', '@story:P2', '@withdrawal'] }, async ({ page, browser }) => {
      const cmid = fixture('ADELE_FIXTURE_P_CM_SWITCHABLE');
      const alpha = fixture('ADELE_FIXTURE_P_A');
      const beta = Number(fixture('ADELE_FIXTURE_P_B_ID'));

      await test.step('starting point: "all" - Alpha sees both, on the page and from the server', async () => {
        expect(storedEmbedding(cmid).userlist, 'the fixture starts at "all"').toBe(1);
        await loginAs(page, alpha, fixturePassword());
        await expect.poll(() => shownLearners(page), { timeout: 60_000 }).toBe(`${ALPHA}, ${BETA}`);
        expect(await rosterThroughSwitchable(page)).toBe(`${ALPHA}, ${BETA}`);
        expect(await rosterThroughSwitchable(page, beta), 'a request naming Beta').toBe(`${ALPHA}, ${BETA}`);
      });

      // A page Alpha opened BEFORE the change, kept open in a second window.
      const before = await browser.newContext({ baseURL: env.baseUrl });
      const stale = await before.newPage();
      await test.step('Alpha keeps a page open from before the change', async () => {
        await loginAs(stale, alpha, fixturePassword());
        expect(await shownLearners(stale)).toBe(`${ALPHA}, ${BETA}`);
      });

      await test.step('the teacher sets the same activity to "only own results" and saves', async () => {
        await loginAs(page, env.adminUser, env.adminPassword);
        await editEmbedding(page, cmid, { results: RESULTS_OWN });
        expect(storedEmbedding(cmid).userlist, 'stored as "only own"').toBe(2);
        drainTaskQueue();
      });

      await test.step('the page opened before: reloaded, Beta is gone', async () => {
        await stale.reload();
        await expect(stale.locator('#adele-userlist-table tbody tr').first()).toBeVisible({ timeout: 60_000 });
        await stale.waitForLoadState('networkidle');
        expect(learnersIn(await participantList(stale))).toBe(ALPHA);
        await expect(stale.locator('#region-main'), 'not named anywhere on the page').not.toContainText(BETA);
        await before.close();
      });

      await test.step('a fresh session: only the own row, and the server no longer sends Beta', async () => {
        await loginAs(page, alpha, fixturePassword());
        const received: string[] = [];
        page.on('response', async (response) => {
          if (response.url().includes('/lib/ajax/service.php')) {
            received.push(await response.text().catch(() => ''));
          }
        });
        expect(await shownLearners(page)).toBe(ALPHA);
        page.removeAllListeners('response');
        expect(received.join('\n'), 'the answers carry the own row').toContain(ALPHA);
        expect(received.join('\n'), 'and not the other learner').not.toContain(BETA);
      });

      await test.step('the requests that worked before no longer return Beta', async () => {
        expect(await rosterThroughSwitchable(page)).toBe(ALPHA);
        expect(await rosterThroughSwitchable(page, beta), 'a request naming Beta').toBe(ALPHA);
      });

      await test.step('the teacher keeps the view their role gives them', async () => {
        await loginAs(page, fixture('ADELE_FIXTURE_P_TEACHER'), fixturePassword());
        expect(await shownLearners(page)).toBe(`${ALPHA}, ${BETA}`);
      });

      await test.step('back to "all": Alpha sees both again - and somebody outside still nothing', async () => {
        await loginAs(page, env.adminUser, env.adminPassword);
        await editEmbedding(page, cmid, { results: RESULTS_ALL });
        expect(storedEmbedding(cmid).userlist).toBe(1);
        drainTaskQueue();
        await loginAs(page, alpha, fixturePassword());
        expect(await shownLearners(page)).toBe(`${ALPHA}, ${BETA}`);
        expect(await rosterThroughSwitchable(page)).toBe(`${ALPHA}, ${BETA}`);
        await loginAs(page, fixture('ADELE_FIXTURE_P_OUTSIDER'), fixturePassword());
        expect(await rosterThroughSwitchable(page)).toBe('denied (nopermissions)');
      });
    });
});

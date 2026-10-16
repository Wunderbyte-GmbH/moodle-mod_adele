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
 * participants-by-role - local_adele #587: the teachers of a course are not
 * the learners of its learning paths, and a change of role changes sides.
 *
 * Every enrolment into a course that embeds a path used to subscribe to it,
 * whatever the role: teachers stood on the participant list with 0 % and a
 * rank and were enrolled into the starting course. A learner now is who takes
 * part in the course (one of the site's graded roles, through a role of their own - local_adele #593).
 *
 * Fixture: the course E2EP of chain P. Its teachers - with and without
 * editing rights - and "Privacy Switch", who starts as a teacher without
 * editing rights. The role is changed on Moodle's participants page; the
 * enrolment is never touched.
 *
 *   teacher only              not on the list, no access to the starting course
 *   + participant's role      on the list, starting course open
 *   participant's role gone   off the list at once, starting course closed
 *
 * The learning history of somebody who left is deleted after a grace period
 * of five minutes, unless they are a participant again by then - that part is
 * enrol_adele's and is tested there (participant_role_test).
 *
 * @copyright   2026 Wunderbyte GmbH
 * @copyright   2026 Ralf Erlebach
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { test, expect, Page } from '@playwright/test';
import { env, loginAs } from '../support/env';
import {
  accessThroughAdele,
  courseAccess,
  drainTaskQueue,
  expectCourseClosedAfterTasks,
  expectCourseOpenAfterTasks,
  fixture,
  fixtureCourse,
  fixturePassword,
  participantList,
  reseedFixtures,
  setCourseRoles,
  setEnrolmentStatus,
} from '../support/fixtures';

const SWITCH = 'Privacy Switch';

/**
 * The last names on the participant list of the embedding, as the
 * administrator sees it.
 *
 * @param page The page.
 */
async function listed(page: Page): Promise<string[]> {
  await loginAs(page, env.adminUser, env.adminPassword);
  await page.goto(`/mod/adele/view.php?id=${fixture('ADELE_FIXTURE_P_CM_ALL')}`);
  await expect(page.locator('#adele-userlist-table tbody tr').first()).toBeVisible({ timeout: 60_000 });
  await page.waitForLoadState('networkidle');
  return (await participantList(page)).map((row) => row.split(' | ')[0]).sort();
}

/**
 * Log in as the person who changes sides.
 *
 * @param page The page.
 */
async function asSwitch(page: Page): Promise<void> {
  await loginAs(page, fixture('ADELE_FIXTURE_P_SWITCH'), fixturePassword());
}

// Every file starts from the seeded state, whatever ran before it
// (support/fixtures: reseedFixtures; E2E issue, section 3B).
test.beforeAll(() => reseedFixtures());

test.describe('ADELE-E2E-P — who takes part: the role decides (#587)', () => {
  test('a teacher is no learner; with the participant\'s role they are; without it again not', { tag: ['@story:P2'] }, async ({ page }) => {
    const host = fixture('ADELE_FIXTURE_P_HOST');
    const t01 = fixtureCourse('T01');

    await test.step('teachers of the course are not on its participant list', async () => {
      drainTaskQueue();
      const names = await listed(page);
      // The learners, and the collaborator - who sits in the course as a participant.
      expect(names).toEqual(expect.arrayContaining(['Privacy Alpha', 'Privacy Beta', 'Privacy Collaborator']));
      for (const teacher of ['Privacy Teacher', 'Privacy Tutor', SWITCH]) {
        expect(names, `${teacher} teaches the course`).not.toContain(teacher);
      }
      await asSwitch(page);
      expect(await courseAccess(page, t01), 'a teacher is not carried into the starting course').not.toBe('open');
    });

    await test.step('given the participant\'s role as well: a learner of the path', async () => {
      await loginAs(page, env.adminUser, env.adminPassword);
      await setCourseRoles(page, host, SWITCH, ['Non-editing teacher', 'Student']);
      drainTaskQueue();
      await expect.poll(() => listed(page), { timeout: 60_000, intervals: [2_000, 5_000] }).toContain(SWITCH);
      await asSwitch(page);
      await expectCourseOpenAfterTasks(page, t01, 'a participant is taken into the starting course');
    });

    await test.step('the participant\'s role taken away: off the list at once, access withdrawn', async () => {
      await loginAs(page, env.adminUser, env.adminPassword);
      await setCourseRoles(page, host, SWITCH, ['Non-editing teacher']);
      expect(await listed(page), 'no waiting for a task: the list follows the role').not.toContain(SWITCH);
      await asSwitch(page);
      await expectCourseClosedAfterTasks(page, t01, 'who only teaches the course loses the starting course');
    });
  });

  test('suspended by hand: off the list of the course, the path stays (local_adele #588)', { tag: ['@story:P2'] }, async ({ page }) => {
    // The collaborator sits in the course as an ordinary participant, with a
    // manual enrolment, and is a learner of the path.
    const host = fixture('ADELE_FIXTURE_P_HOST');
    const t01 = fixtureCourse('T01');
    const person = 'Privacy Collaborator';
    const asPerson = () => loginAs(page, fixture('ADELE_FIXTURE_P_COLLABORATOR'), fixturePassword());

    await test.step('the precondition: listed, and in the starting course', async () => {
      drainTaskQueue();
      expect(await listed(page)).toContain(person);
      await asPerson();
      await expectCourseOpenAfterTasks(page, t01, 'a participant of the course is on the path');
    });

    await test.step('a teacher suspends the enrolment on Moodle\'s participants page', async () => {
      await loginAs(page, env.adminUser, env.adminPassword);
      await setEnrolmentStatus(page, host, person, 'Suspended');
      drainTaskQueue();
    });

    await test.step('off the learning path\'s list of the course - and still on the path', async () => {
      expect(await listed(page), 'taken out of the course by hand').not.toContain(person);
      // A suspension in the host course is without consequence for the path
      // itself (earlier decision): the starting course stays open.
      await asPerson();
      expect(await accessThroughAdele(page, t01), 'the path is untouched').toBe('open');
    });

    await test.step('reactivated: back on the list', async () => {
      await loginAs(page, env.adminUser, env.adminPassword);
      await setEnrolmentStatus(page, host, person, 'Active');
      expect(await listed(page)).toContain(person);
    });
  });
});

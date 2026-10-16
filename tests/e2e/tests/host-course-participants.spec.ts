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
 * host-course-participants - chain H1 of the E2E plan (section 16).
 *
 *     a teacher embeds a learning path into a course
 *     -> the course's participants are taken into the entry course
 *     -> a participant can actually open that course
 *     -> somebody outside the host course is not
 *
 * The activity is created THROUGH THE INTERFACE here, not by a seed. Creating
 * it in PHP would skip the very authoring step this chain is about and would
 * still produce enrolments, so the test would pass while the form was broken.
 *
 * The host course (E2EHOST) comes from the fixtures with two members and one
 * deliberate outsider, because an effect that reaches everybody proves
 * nothing (plan section 2 G).
 *
 * @copyright   2026 Wunderbyte GmbH
 * @copyright   2026 Ralf Erlebach
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { test, expect, Page } from '@playwright/test';
import { env, loginAs } from '../support/env';
import {
  drainTaskQueue,
  expectCourseClosed,
  expectCourseOpenAfterTasks,
  fixture,
  fixtureCourse,
  fixturePassword,
  participantList,
  referencePath,
  reseedFixtures,
  runLabel,
} from '../support/fixtures';
import {
  chooseInAutocomplete,
  embedWith,
  onPath,
  participantRow,
  SOURCE_ANY,
  SOURCE_START,
  SOURCE_THIS,
} from '../support/host';

/**
 * Embed the reference path into the host course, through the form.
 *
 * Creating the activity in PHP would skip the authoring step these chains are
 * about and would still produce the enrolments, so a broken form would pass.
 *
 * @param page The page, authenticated as somebody who may edit the course.
 * @param hostcourse The host course id.
 * @returns The name the activity was given.
 */
async function embedReferencePath(page: Page, hostcourse: string): Promise<string> {
  // Reuse an embedding that is already there. Every chain in this file needs
  // the path embedded, and each of them has to work on its own - but a second
  // activity for the same path in the same course is not what any of them
  // means by "the path is embedded", and it would double the enrolment
  // effects they measure.
  await page.goto(`/course/view.php?id=${hostcourse}`);
  const existing = page.getByRole('link', { name: /^E2E Lernpfad / });
  if (await existing.count()) {
    return (await existing.first().innerText()).trim();
  }

  const activityname = runLabel('E2E Lernpfad');

  // Straight to the activity form rather than through the chooser: the
  // chooser is core's UI and is covered by core's own tests, while the form
  // below is mod_adele's.
  await page.goto(`/course/modedit.php?add=adele&course=${hostcourse}&section=0`);

  // #id_name, not the label: mod_adele calls both the activity name and the
  // path selector "Learning path", so the label is ambiguous. A Moodle form
  // id is the documented stable handle and the last step the plan's selector
  // contract allows (section 21).
  await page.locator('#id_name').fill(activityname);

  // Both selectors are Moodle autocompletes: the <select> behind them is
  // hidden, so they are driven the way a person drives them - type, then pick
  // the entry by its name, never by position.
  await chooseInAutocomplete(page, 'Chosen Learning Path', referencePath.name);
  await chooseInAutocomplete(page, 'Learning path enrolment', 'for people enrolled in this course');

  await page.getByRole('button', { name: /Save and return to course|Speichern und zum Kurs/i }).click();
  await expect(page.getByRole('main').getByRole('link', { name: activityname })).toBeVisible();

  return activityname;
}

// Every file starts from the seeded state, whatever ran before it
// (support/fixtures: reseedFixtures; E2E issue, section 3B).
test.beforeAll(() => reseedFixtures());

test.describe('ADELE-E2E-H1 — embedding a path into a course carries its participants', () => {
  test('the participants of the host course reach the entry course, outsiders do not', { tag: ['@story:H1'] }, async ({ page }) => {
    const hostcourse = fixture('ADELE_FIXTURE_HOST_COURSE');
    const member = fixture('ADELE_FIXTURE_HOST_MEMBER_1');
    const outsider = fixture('ADELE_FIXTURE_HOST_OUTSIDER');
    const entrycourse = fixtureCourse('T01');
    let activityname = '';

    await test.step('precondition: neither member nor outsider is in the entry course', async () => {
      await loginAs(page, member, fixturePassword());
      await expectCourseClosed(page, entrycourse, 'the member has not been carried anywhere yet');
      await loginAs(page, outsider, fixturePassword());
      await expectCourseClosed(page, entrycourse, 'the outsider must never be carried');
    });

    await test.step('a teacher embeds the reference path into the host course', async () => {
      await loginAs(page, env.adminUser, env.adminPassword);
      activityname = await embedReferencePath(page, hostcourse);
    });

    await test.step('the host member is carried into the entry course', async () => {
      await loginAs(page, member, fixturePassword());
      await expectCourseOpenAfterTasks(
        page,
        entrycourse,
        'the member of the host course must be carried into the entry course of the embedded path'
      );
    });

    await test.step('the outsider is still refused', async () => {
      // The queue has been run to completion above, so this is the final
      // state and not a race the outsider happens to win.
      drainTaskQueue();
      await loginAs(page, outsider, fixturePassword());
      await expectCourseClosed(
        page,
        entrycourse,
        'embedding must not carry anybody who is not in the host course'
      );
    });

    await test.step('the member sees the embedded path in the activity', async () => {
      await loginAs(page, member, fixturePassword());
      await page.goto(`/course/view.php?id=${hostcourse}`);
      // In the main region: Moodle's course index links the same activity
      // again, and it is open for some people and not for others.
      await page.getByRole('main').getByRole('link', { name: activityname }).click();
      // The learning path itself renders, with its nodes carrying the
      // machine-readable state from #574.
      await expect(page.locator('[data-testid^="learningpath-node-"]').first())
        .toBeVisible({ timeout: 60_000 });
    });
  });
});

test.describe('ADELE-E2E-H1b — leaving the host course withdraws the access it granted', () => {
  test('unenrolling one participant closes their access, and only theirs', { tag: ['@story:H1', '@withdrawal'] }, async ({ page }) => {
    const hostcourse = fixture('ADELE_FIXTURE_HOST_COURSE');
    const leaving = fixture('ADELE_FIXTURE_HOST_MEMBER_1');
    const leavingname = fixture('ADELE_FIXTURE_HOST_MEMBER_1_NAME');
    const staying = fixture('ADELE_FIXTURE_HOST_MEMBER_2');
    const entrycourse = fixtureCourse('T01');

    await test.step('precondition: both members are carried into the entry course', async () => {
      await loginAs(page, env.adminUser, env.adminPassword);
      await embedReferencePath(page, hostcourse);

      for (const member of [leaving, staying]) {
        await loginAs(page, member, fixturePassword());
        await expectCourseOpenAfterTasks(
          page,
          entrycourse,
          `${member} is in the host course, so the embedded path must carry them`
        );
      }
    });

    await test.step('a teacher removes one participant from the host course', async () => {
      await loginAs(page, env.adminUser, env.adminPassword);
      await page.goto(`/user/index.php?id=${hostcourse}`);

      // The row is found by the person's name, never by position: the list is
      // sorted by whatever the page remembers.
      const row = page.locator('table#participants tbody tr').filter({ hasText: leavingname });
      await expect(row).toHaveCount(1);
      // Core marks the action with data-action - its own stable hook, since
      // the element has no accessible name of its own.
      //
      // The link is FOLLOWED rather than clicked. A click is handled by an
      // AMD module that opens a modal, and the handler is bound after the
      // markup: a click that lands earlier does nothing at all, which no
      // amount of waiting repairs. The href is core's own no-JavaScript
      // route to the same action and ends on the same confirmation.
      const action = row.locator('[data-action="unenrol"]').first();
      const href = await action.getAttribute('href');
      expect(href, 'the participants list offers no unenrol action for this person').toBeTruthy();
      await page.goto(new URL(href as string, page.url()).toString());

      await page.getByRole('button', { name: /^(Unenrol|Abmelden|Continue|Weiter)$/ }).first().click();
      await expect(page.locator('table#participants tbody tr').filter({ hasText: leavingname }))
        .toHaveCount(0);
    });

    drainTaskQueue();

    await test.step('the access that membership granted is gone', async () => {
      await loginAs(page, leaving, fixturePassword());
      await expectCourseClosed(
        page,
        entrycourse,
        'once the host membership ends, the enrolment it caused must not keep the course open'
      );
    });

    await test.step('the remaining participant keeps their access', async () => {
      // Withdrawal must be targeted. A sweep that clears everybody would pass
      // the previous step and be a far worse defect.
      await loginAs(page, staying, fixturePassword());
      await expectCourseOpenAfterTasks(
        page,
        entrycourse,
        'the other member is still in the host course and must keep their access'
      );
    });
  });
});

test.describe('ADELE-E2E-H2 — the second participant source: the starting node course', () => {
  test('people in the entry course are carried, outsiders are not', { tag: ['@story:H2'] }, async ({ page }) => {
    const hostcourse = fixture('ADELE_FIXTURE_HOST_COURSE_2');
    const entrylearner = fixture('ADELE_FIXTURE_ENTRY_LEARNER');
    const outsider = fixture('ADELE_FIXTURE_HOST_OUTSIDER');
    const activityname = runLabel('E2E Startknotenquelle');

    await test.step('precondition: neither of them is in the host course', async () => {
      for (const person of [entrylearner, outsider]) {
        await loginAs(page, person, fixturePassword());
        await expectCourseClosed(page, hostcourse, `${person} has not been carried anywhere yet`);
      }
    });

    await test.step('a teacher embeds the path with the starting node as the source', async () => {
      await loginAs(page, env.adminUser, env.adminPassword);
      await page.goto(`/course/modedit.php?add=adele&course=${hostcourse}&section=0`);
      await page.locator('#id_name').fill(activityname);
      await chooseInAutocomplete(page, 'Chosen Learning Path', referencePath.name);
      // The source under test, and the one thing that differs from H1.
      await chooseInAutocomplete(page, 'Learning path enrolment', 'for people enrolled in a starting node');
      await page.getByRole('button', { name: /Save and return to course|Speichern und zum Kurs/i }).click();
      await expect(page.getByRole('main').getByRole('link', { name: activityname })).toBeVisible();
    });

    await test.step('the person enrolled in the entry course reaches the host course', async () => {
      await loginAs(page, entrylearner, fixturePassword());
      await expectCourseOpenAfterTasks(
        page,
        hostcourse,
        'somebody enrolled in the starting node course must be carried into the host course'
      );
    });

    await test.step('somebody outside every course of the path is not', async () => {
      drainTaskQueue();
      await loginAs(page, outsider, fixturePassword());
      await expectCourseClosed(
        page,
        hostcourse,
        'this source must not carry a person who is in no course of the path'
      );
    });

    await test.step('somebody only in a LATER node course is not either', async () => {
      // The node learner is manually in T03 only. Other learning paths of
      // the fixture site have since enrolled them into T01 as well - but an
      // enrolment ADELE created itself does not count (mod_adele #37), so
      // the starting node source must not reach them.
      await loginAs(page, fixture('ADELE_FIXTURE_NODE_LEARNER'), fixturePassword());
      await expectCourseClosed(
        page,
        hostcourse,
        'the starting node source must not carry a person who is only in a later node course'
      );
    });
  });
});

test.describe('ADELE-E2E-H2 — the third participant source: any course of the path', () => {
  test('a person only in a later node course is carried, outsiders are not', { tag: ['@story:H2'] }, async ({ page }) => {
    const hostcourse = fixture('ADELE_FIXTURE_HOST_COURSE_3');
    const nodelearner = fixture('ADELE_FIXTURE_NODE_LEARNER');
    const outsider = fixture('ADELE_FIXTURE_HOST_OUTSIDER');

    await test.step('precondition: neither of them is in the host course', async () => {
      for (const person of [nodelearner, outsider]) {
        await loginAs(page, person, fixturePassword());
        await expectCourseClosed(page, hostcourse, `${person} has not been carried anywhere yet`);
      }
    });

    await test.step('a teacher embeds the path with "any course" as the source', async () => {
      await loginAs(page, env.adminUser, env.adminPassword);
      await embedWith(page, hostcourse, runLabel('E2E Knotenquelle'), [SOURCE_ANY]);
    });

    await test.step('the person in a later node course reaches the host course', async () => {
      await loginAs(page, nodelearner, fixturePassword());
      await expectCourseOpenAfterTasks(page, hostcourse, 'any course of the path must carry them');
    });

    await test.step('the outsider does not', async () => {
      drainTaskQueue();
      await loginAs(page, outsider, fixturePassword());
      await expectCourseClosed(page, hostcourse, 'this source must not carry a person in no course of the path');
    });
  });
});

test.describe('ADELE-E2E-H2 — two sources combined: this course AND the starting node', () => {
  test('each source carries its own people, nobody else', { tag: ['@story:H2'] }, async ({ page }) => {
    const hostcourse = fixture('ADELE_FIXTURE_HOST_COURSE_6');
    const member = fixture('ADELE_FIXTURE_HOST_MEMBER_1');
    const entrylearner = fixture('ADELE_FIXTURE_ENTRY_LEARNER');
    const outsider = fixture('ADELE_FIXTURE_HOST_OUTSIDER');

    await test.step('a teacher embeds the path with both sources', async () => {
      await loginAs(page, env.adminUser, env.adminPassword);
      await embedWith(page, hostcourse, runLabel('E2E Kombination'), [SOURCE_THIS, SOURCE_START]);
    });

    await test.step('"this course": the host member is subscribed and reaches the entry course', async () => {
      await loginAs(page, member, fixturePassword());
      await expectCourseOpenAfterTasks(page, fixtureCourse('T01'), 'membership of the host course must carry them');
    });

    await test.step('"starting node": the entry course learner reaches the host course', async () => {
      await loginAs(page, entrylearner, fixturePassword());
      await expectCourseOpenAfterTasks(page, hostcourse, 'the starting node course must carry them');
    });

    await test.step('the outsider reaches neither', async () => {
      drainTaskQueue();
      await loginAs(page, outsider, fixturePassword());
      await expectCourseClosed(page, hostcourse, 'neither source applies to the outsider');
      await expectCourseClosed(page, fixtureCourse('T01'), 'neither source applies to the outsider');
    });
  });
});

/**
 * The last names on the learning path's own participant list of an
 * embedding, as the administrator sees it in the course.
 *
 * @param page The page.
 * @param hostcourse The course.
 * @param activity The embedding's name.
 */
async function pathRoster(page: Page, hostcourse: string, activity: string): Promise<string[]> {
  await loginAs(page, env.adminUser, env.adminPassword);
  await page.goto(`/course/view.php?id=${hostcourse}`);
  await page.getByRole('main').getByRole('link', { name: activity }).click();
  await expect(page.locator('#adele-userlist-table tbody tr').first()).toBeVisible({ timeout: 60_000 });
  await page.waitForLoadState('networkidle');
  return (await participantList(page)).map((row) => row.split(' | ')[0]);
}

test.describe('ADELE-E2E-H3 — the host enrolment modes', () => {
  test('"hidden": the person is listed as suspended and cannot enter the host course', { tag: ['@story:H3'] }, async ({ page }) => {
    const hostcourse = fixture('ADELE_FIXTURE_HOST_COURSE_4');
    const entrylearner = fixture('ADELE_FIXTURE_ENTRY_LEARNER');
    const name = fixture('ADELE_FIXTURE_ENTRY_LEARNER_NAME');
    const activity = runLabel('E2E Modus hidden');

    await test.step('a teacher embeds the path, starting node source, mode "hidden"', async () => {
      await loginAs(page, env.adminUser, env.adminPassword);
      await embedWith(page, hostcourse, activity, [SOURCE_START], 'hidden (as a suspended enrolment)');
      drainTaskQueue();
    });

    await test.step('participants list: present, but suspended', async () => {
      await expect
        .poll(async () => {
          drainTaskQueue();
          const row = await participantRow(page, hostcourse, name);
          return (await row.count()) ? (await row.first().innerText()).replace(/\s+/g, ' ') : '';
        }, { message: 'the person must be listed in the host course', timeout: 60_000 })
        .toMatch(/Suspended|Gesperrt/);
    });

    await test.step('real access: the host course stays closed to them', async () => {
      await loginAs(page, entrylearner, fixturePassword());
      await expectCourseClosed(page, hostcourse, 'a suspended enrolment must not open the course');
    });

    await test.step('the learning path\'s list in the course: there - suspended by ADELE is still a learner', async () => {
      // local_adele #588 (addendum): a suspension ADELE made itself counts,
      // one made by hand does not (participants-by-role.spec.ts).
      const names = await pathRoster(page, hostcourse, activity);
      const lastname = name.split(' ').slice(1).join(' ') || name;
      expect(names, `"${name}" must be on the path's list`).toContain(lastname);
    });
  });

  test('"none": the person is not enrolled in the host course at all', { tag: ['@story:H3'] }, async ({ page }) => {
    const hostcourse = fixture('ADELE_FIXTURE_HOST_COURSE_5');
    const entrylearner = fixture('ADELE_FIXTURE_ENTRY_LEARNER');
    const name = fixture('ADELE_FIXTURE_ENTRY_LEARNER_NAME');

    await test.step('a teacher embeds the path, starting node source, mode "none"', async () => {
      await loginAs(page, env.adminUser, env.adminPassword);
      await embedWith(page, hostcourse, runLabel('E2E Modus none'), [SOURCE_START], 'no enrolment at all');
      drainTaskQueue();
    });

    await test.step('participants list: not there', async () => {
      drainTaskQueue();
      const row = await participantRow(page, hostcourse, name);
      await expect(row, 'mode "none" must not enrol the person into the host course').toHaveCount(0);
    });

    await test.step('real access: the host course is closed to them', async () => {
      await loginAs(page, entrylearner, fixturePassword());
      await expectCourseClosed(page, hostcourse, 'without an enrolment the course stays closed');
    });
  });
});

test.describe('ADELE-E2E-H3 — the host enrolment modes for the source "any course of the path"', () => {
  test('"hidden": listed as suspended, the host course stays closed', { tag: ['@story:H3'] }, async ({ page }) => {
    const hostcourse = fixture('ADELE_FIXTURE_HOST_COURSE_7');
    const nodelearner = fixture('ADELE_FIXTURE_NODE_LEARNER');
    const name = fixture('ADELE_FIXTURE_NODE_LEARNER_NAME');

    await test.step('precondition: not in the host course', async () => {
      await loginAs(page, nodelearner, fixturePassword());
      await expectCourseClosed(page, hostcourse, 'nothing has been embedded yet');
    });

    await test.step('a teacher embeds the path, "any course" source, mode "hidden"', async () => {
      await loginAs(page, env.adminUser, env.adminPassword);
      await embedWith(page, hostcourse, runLabel('E2E Modus hidden Knotenquelle'), [SOURCE_ANY], 'hidden (as a suspended enrolment)');
      drainTaskQueue();
    });

    await test.step('participants list: present, but suspended', async () => {
      await expect
        .poll(async () => {
          drainTaskQueue();
          const row = await participantRow(page, hostcourse, name);
          return (await row.count()) ? (await row.first().innerText()).replace(/\s+/g, ' ') : '';
        }, { message: 'the person must be listed in the host course', timeout: 60_000 })
        .toMatch(/Suspended|Gesperrt/);
    });

    await test.step('real access: closed', async () => {
      await loginAs(page, nodelearner, fixturePassword());
      await expectCourseClosed(page, hostcourse, 'a suspended enrolment must not open the course');
    });
  });

  test('"none": not enrolled in the host course at all', { tag: ['@story:H3'] }, async ({ page }) => {
    const hostcourse = fixture('ADELE_FIXTURE_HOST_COURSE_8');
    const nodelearner = fixture('ADELE_FIXTURE_NODE_LEARNER');
    const name = fixture('ADELE_FIXTURE_NODE_LEARNER_NAME');

    await test.step('a teacher embeds the path, "any course" source, mode "none"', async () => {
      await loginAs(page, env.adminUser, env.adminPassword);
      await embedWith(page, hostcourse, runLabel('E2E Modus none Knotenquelle'), [SOURCE_ANY], 'no enrolment at all');
      drainTaskQueue();
    });

    await test.step('participants list: not there; real access: closed', async () => {
      drainTaskQueue();
      await expect(await participantRow(page, hostcourse, name), 'mode "none" must not enrol').toHaveCount(0);
      await loginAs(page, nodelearner, fixturePassword());
      await expectCourseClosed(page, hostcourse, 'without an enrolment the course stays closed');
    });
  });
});

test.describe('ADELE-E2E-H2 — an enrolment ADELE made carries nobody onto a further path (#37)', () => {
  test('the host enrolment of one embedding does not subscribe to another path in the same course', { tag: ['@story:H2'] }, async ({ page }) => {
    const hostcourse = fixture('ADELE_FIXTURE_HOST_COURSE_9');
    const entrylearner = fixture('ADELE_FIXTURE_ENTRY_LEARNER');
    // A path the entry course learner is not on. Linear A2 is not suitable:
    // after the seed they are already on it (measured; why is not part of
    // this chain).
    const otherpath = fixture('ADELE_FIXTURE_PATH_QUIVALENZUMFORMUNG_NEU');
    const othername = 'Äquivalenzumformung (neu)';

    await test.step('precondition: the entry course learner is not on the other path', async () => {
      // (On the reference path they may well be already: the earlier chains
      // of this file embed it for the starting node.)
      expect(onPath(entrylearner, otherpath)).toBe(false);
    });

    await test.step('the course embeds the other path for its own members, and the reference path for its starting node', async () => {
      await loginAs(page, env.adminUser, env.adminPassword);
      await embedWith(page, hostcourse, runLabel('E2E Quer andere Pfad'), [SOURCE_THIS], undefined, othername);
      await embedWith(page, hostcourse, runLabel('E2E Quer Referenzpfad'), [SOURCE_START]);
      drainTaskQueue();
    });

    await test.step('the entry course learner is carried into the course by ADELE', async () => {
      await loginAs(page, entrylearner, fixturePassword());
      await expectCourseOpenAfterTasks(page, hostcourse, 'the starting node source carries them in');
    });

    await test.step('and that enrolment does not put them on the other path', async () => {
      drainTaskQueue();
      expect(onPath(entrylearner, otherpath), 'an enrolment ADELE made is no membership of the course').toBe(false);
    });
  });
});

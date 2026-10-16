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
 * learner-data - chain P of the E2E plan (section 17): who sees whose
 * learning data in a course that embeds a path.
 *
 * Fixture: the path "E2E Lernerdaten" (linear T01 -> T02 -> T03) embedded
 * TWICE into the course E2EP:
 *
 *   "E2E P own results"   Participant results = Only own results
 *   "E2E P all results"   Participant results = Results of all other participants
 *
 * Learner Alpha completes T01 (1/3, 33.33 %), learner Beta T01 and T02 (2/3,
 * 66.67 %). Around them: a teacher with and one without editing rights, a
 * collaborator of this path who sits in the course as a participant, the two
 * ADELE system roles (in no course), and two people of ANOTHER course that
 * embeds nothing - a participant and a teacher.
 *
 *   P1  a learner sees the own status, progress and percentage - and nothing
 *       of the other learner, neither on the page nor in what the server sends
 *   P2  with "results of all other participants" both learners are listed
 *       with the correct values; the teacher reaches a learner's detail view
 *   P3  the same question for every role (table below); the teacher without
 *       editing rights sees what the teacher sees and changes nothing (#39)
 *   P4  a participant asks for foreign data directly: foreign row, foreign
 *       detail view, direct URL - through every context they can name
 *
 * P4 found three ways around the boundary (session protocol, part 30); they
 * are closed in local_adele and pinned here: rights that come from a course
 * count only in a course that embeds the path, and the same boundary holds
 * for changing a learner's path.
 *
 * Building a path and teaching a course that uses it are two different
 * things (local_adele #586, addendum of 2026-10-07): the ADELE manager, the
 * collaborator of this path and the assistant get no learner data from
 * building or managing paths - only a teacher of the embedding course does,
 * and the site administrator.
 *
 * The path is embedded in a second course, E2EP2, as well, with a learner of
 * its own (Gamma). A roster belongs to its course (local_adele #588): Gamma is
 * on the same path and appears in none of E2EP's lists, nor Alpha and Beta in
 * E2EP2's; only the site administrator, asking outside any course, sees all.
 *
 * Everybody enrolled in the embedding course is subscribed to the path, staff
 * included - the list therefore shows the teachers with 0/3 as well. The
 * chain pins the two learners and leaves the staff rows alone (open question,
 * session protocol).
 *
 * @copyright   2026 Wunderbyte GmbH
 * @copyright   2026 Ralf Erlebach
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { test, expect, Page } from '@playwright/test';
import { env, loginAs } from '../support/env';
import {
  callService,
  completeCourse,
  drainTaskQueue,
  fixture,
  fixtureCourse,
  fixturePassword,
  nodeStatus,
  participantList,
  reseedFixtures,
  runCompletion,
} from '../support/fixtures';

const ALPHA = 'Privacy Alpha';
const BETA = 'Privacy Beta';
// The learner of ANOTHER course that embeds the same path (local_adele #588).
const GAMMA = 'Privacy Gamma';
const ALPHA_ROW = `${ALPHA} | 33.33 | 1/3 | rank 2`;
const BETA_ROW = `${BETA} | 66.67 | 2/3 | rank 1`;

const pathId = () => Number(fixture('ADELE_FIXTURE_PATH_P'));
const contextId = (name: string) => Number(fixture(`ADELE_FIXTURE_P_${name}_CONTEXT`));
const userId = (who: string) => Number(fixture(`ADELE_FIXTURE_P_${who}_ID`));

/**
 * Log in as one of the people of this chain.
 *
 * @param page The page.
 * @param who ADMIN, MANAGER, ASSISTANT or a key of the P fixture (A, B, TEACHER ...).
 */
async function loginAsPerson(page: Page, who: string): Promise<void> {
  if (who === 'ADMIN') {
    await loginAs(page, env.adminUser, env.adminPassword);
  } else if (who === 'MANAGER' || who === 'ASSISTANT') {
    await loginAs(page, fixture(`ADELE_FIXTURE_${who}`), fixturePassword());
  } else {
    await loginAs(page, fixture(`ADELE_FIXTURE_P_${who}`), fixturePassword());
  }
}

/**
 * Open one of the embeddings and say what the person got:
 * "no access", or the kind of view and the rows of the learners.
 *
 * @param page The page, logged in.
 * @param which OWN or ALL (course E2EP), or SECOND (the same path in course E2EP2).
 * @returns e.g. "participant view: Privacy Alpha | 33.33 | 1/3 | rank 2".
 */
async function embedding(page: Page, which: 'OWN' | 'ALL' | 'SECOND'): Promise<string> {
  await page.goto(`/mod/adele/view.php?id=${fixture(`ADELE_FIXTURE_P_CM_${which}`)}`);
  if (!page.url().includes('/mod/adele/view.php')) {
    return 'no access';
  }
  const table = page.locator('#adele-userlist-table');
  await expect(table).toBeVisible({ timeout: 60_000 });
  await expect(table.locator('tbody tr').first()).toBeVisible({ timeout: 30_000 });
  await page.waitForLoadState('networkidle');
  const view = (await table.locator('thead th').count()) === 6 ? 'teacher view' : 'participant view';
  const learners = (await participantList(page))
    .filter((row) => [ALPHA, BETA, GAMMA].some((name) => row.startsWith(name)));
  return `${view}: ${learners.sort().join(' ; ') || 'neither learner'}`;
}

/**
 * Ask the server for the roster of the path, in a given context.
 *
 * @param page The page, logged in.
 * @param context The context the request claims to come from.
 * @param userid The (untrusted) userid parameter.
 * @returns The last names of the two learners in the answer, or "denied".
 */
async function roster(page: Page, context: number, userid = 0): Promise<string> {
  const answer = await callService(page, 'local_adele_get_user_path_relations', {
    userid,
    learningpathid: pathId(),
    contextid: context,
  });
  if (answer.error) {
    return `denied (${answer.error})`;
  }
  const names = (answer.data as { lastname: string }[]).map((row) => row.lastname)
    .filter((name) => [ALPHA, BETA, GAMMA].includes(name));
  return names.sort().join(', ') || 'neither learner';
}

/**
 * Ask the server for one learner's path snapshot (it carries the e-mail
 * address), in a given context.
 *
 * @param page The page, logged in.
 * @param who Whose snapshot.
 * @param context The context the request claims to come from.
 * @returns The last name in the answer, or "denied".
 */
async function detail(page: Page, who: string, context: number): Promise<string> {
  const answer = await callService(page, 'local_adele_get_user_path_relation', {
    learningpathid: pathId(),
    userpathid: userId(who),
    contextid: context,
  });
  return answer.error ? `denied (${answer.error})` : (answer.data as { lastname: string }).lastname;
}

/**
 * The three node states of the path as the person logged in sees them.
 *
 * @param page The page.
 * @param activity The embedding's name.
 */
async function nodeStates(page: Page, activity: string): Promise<string> {
  const states: string[] = [];
  for (const nodeid of ['dndnode_1', 'dndnode_2', 'dndnode_3']) {
    states.push(await nodeStatus(page, fixture('ADELE_FIXTURE_P_HOST'), activity, nodeid));
  }
  return states.join(' ');
}

// Every file starts from the seeded state, whatever ran before it
// (support/fixtures: reseedFixtures; E2E issue, section 3B).
test.beforeAll(() => reseedFixtures());

test.describe('ADELE-E2E-P — learner data in a course that embeds a path', () => {
  // One story: the later tests ask about the progress the first one creates.
  test.describe.configure({ mode: 'serial' });

  test('P1: a learner sees the own data, and nothing of the other learner', { tag: ['@story:P1'] }, async ({ page }) => {
    await test.step('Alpha completes T01, Beta completes T01 and T02', async () => {
      for (const [who, courses] of [['A', ['T01']], ['B', ['T01', 'T02']]] as const) {
        await loginAsPerson(page, who);
        for (const course of courses) {
          drainTaskQueue();
          await completeCourse(page, fixtureCourse(course));
          runCompletion();
        }
      }
    });

    for (const [who, own, foreign, states] of [
      ['A', ALPHA_ROW, BETA, 'completed accessible not_accessible'],
      ['B', BETA_ROW, ALPHA, 'completed completed accessible'],
    ] as const) {
      await test.step(`${who}: own status, progress and percentage`, async () => {
        await loginAsPerson(page, who);
        await expect.poll(() => { drainTaskQueue(); return nodeStates(page, 'E2E P own results'); },
          { message: 'the own node states', timeout: 90_000, intervals: [2_000, 5_000] }).toBe(states);
        await expect.poll(() => embedding(page, 'OWN'), { timeout: 60_000, intervals: [2_000, 5_000] })
          .toBe(`participant view: ${own}`);
      });

      await test.step(`${who}: not the other learner - not on the page, not in what the server sent`, async () => {
        // Everything the page receives while it loads, not only what it shows.
        const received: string[] = [];
        page.on('response', async (response) => {
          if (response.url().includes('/lib/ajax/service.php')) {
            received.push(await response.text().catch(() => ''));
          }
        });
        await page.goto(`/mod/adele/view.php?id=${fixture('ADELE_FIXTURE_P_CM_OWN')}`);
        await expect(page.locator('#adele-userlist-table tbody tr').first()).toBeVisible({ timeout: 60_000 });
        await page.waitForLoadState('networkidle');
        page.removeAllListeners('response');

        expect(await participantList(page), 'exactly one row: the own one').toEqual([own]);
        await expect(page.locator('#region-main'), 'the page must not name the other learner').not.toContainText(foreign);
        expect(received.join('\n'), 'the answers must have carried the own roster row').toContain(who === 'A' ? ALPHA : BETA);
        expect(received.join('\n'), 'the server must not send the other learner at all').not.toContain(foreign);
      });
    }
  });

  test('P2: with "results of all other participants" both learners are listed, correctly', { tag: ['@story:P2'] }, async ({ page }) => {
    await test.step('a participant sees both learners with status and percentage', async () => {
      await loginAsPerson(page, 'A');
      await expect.poll(() => embedding(page, 'ALL'), { timeout: 60_000, intervals: [2_000, 5_000] })
        .toBe(`participant view: ${ALPHA_ROW} ; ${BETA_ROW}`);
    });

    await test.step('the teacher sees both in either embedding, with a way into the detail view', async () => {
      await loginAsPerson(page, 'TEACHER');
      for (const which of ['OWN', 'ALL'] as const) {
        await expect.poll(() => embedding(page, which), { timeout: 60_000, intervals: [2_000, 5_000] })
          .toBe(`teacher view: ${ALPHA_ROW} | link ; ${BETA_ROW} | link`);
      }
    });

    await test.step('the same path in another course: each course lists its own learners (#588)', async () => {
      await loginAsPerson(page, 'ADMIN');
      // Gamma is on the path - and in none of E2EP's lists (the rows above
      // are exact); here she is alone, and first of one.
      await expect.poll(() => embedding(page, 'SECOND'), { timeout: 60_000, intervals: [2_000, 5_000] })
        .toBe(`teacher view: ${GAMMA} | 0 | 0/3 | rank 1 | link`);
      // The site administrator, asked outside any course: the whole path.
      expect(await roster(page, 1), 'administrator, system context').toBe(`${ALPHA}, ${BETA}, ${GAMMA}`);
      expect(await roster(page, contextId('CM_ALL')), 'administrator, through the embedding in E2EP').toBe(`${ALPHA}, ${BETA}`);
      // Used to get the same: the ADELE manager. Managing paths is no right
      // over learners (#586, addendum).
      await loginAsPerson(page, 'MANAGER');
      expect(await roster(page, 1), 'ADELE manager, system context').toBe('denied (nopermissions)');
      expect(await roster(page, contextId('CM_ALL')), 'ADELE manager, through the embedding').toBe('denied (nopermissions)');
      await loginAsPerson(page, 'TEACHER');
    });

    await test.step("the teacher opens Beta's detail view: Beta's node states", async () => {
      expect(await embedding(page, 'ALL')).toBe(`teacher view: ${ALPHA_ROW} | link ; ${BETA_ROW} | link`);
      await page.locator('#adele-userlist-table tbody tr').filter({ hasText: BETA }).getByRole('link').first().click();
      for (const [nodeid, status] of [['dndnode_1', 'completed'], ['dndnode_2', 'completed'], ['dndnode_3', 'accessible']]) {
        await expect(page.locator(`[data-testid="learningpath-node-${nodeid}"]`), `Beta's ${nodeid}`)
          .toHaveAttribute('data-status', status, { timeout: 60_000 });
      }
    });
  });

  test('P3: what each role sees, as implemented', { tag: ['@story:P3'] }, async ({ page }) => {
    const both = `${ALPHA_ROW} ; ${BETA_ROW}`;
    const bothlinked = `${ALPHA_ROW} | link ; ${BETA_ROW} | link`;
    // Who            "own results"                      "all results"                 roster from server  Beta's detail from server.
    const matrix: [string, string, string, string, string, string][] = [
      // Site administrator: everything.
      ['ADMIN', `teacher view: ${bothlinked}`, `teacher view: ${bothlinked}`, `${ALPHA}, ${BETA}`, BETA,
        'administrator'],
      // Teacher with editing rights: the teacher's view, everything.
      ['TEACHER', `teacher view: ${bothlinked}`, `teacher view: ${bothlinked}`, `${ALPHA}, ${BETA}`, BETA,
        'teacher with editing rights'],
      // Teacher without editing rights (#39): sees everything, like the teacher -
      // local/adele:viewparticipants - and changes nothing (next step, and P4).
      ['TUTOR', `teacher view: ${bothlinked}`, `teacher view: ${bothlinked}`, `${ALPHA}, ${BETA}`, BETA,
        'teacher without editing rights'],
      // Collaborator of THIS path, enrolled as a participant: a participant -
      // the own row only where the embedding says so, never a detail. Working
      // on the path adds nothing (used to: the full roster and Beta's snapshot).
      ['COLLABORATOR', 'participant view: neither learner', `participant view: ${both}`, 'neither learner',
        'denied (nopermissions)', 'collaborator of the path, a participant here'],
      // The ADELE system roles are in no course: no page shows them the
      // embedding, and the server answers neither - not the manager (used
      // to), not the assistant.
      ['MANAGER', 'no access', 'no access', 'denied (nopermissions)', 'denied (nopermissions)', 'ADELE manager'],
      ['ASSISTANT', 'no access', 'no access', 'denied (nopermissions)', 'denied (nopermissions)',
        'ADELE assistant (editor of another path)'],
      // Participant: the own row, or all - as the embedding says; never a detail.
      ['A', `participant view: ${ALPHA_ROW}`, `participant view: ${both}`, ALPHA, 'denied (nopermissions)',
        'participant'],
      // People of another course: nothing.
      ['OUTSIDER', 'no access', 'no access', 'denied (nopermissions)', 'denied (nopermissions)',
        'participant of another course'],
      ['FOREIGN', 'no access', 'no access', 'denied (nopermissions)', 'denied (nopermissions)',
        'teacher of another course'],
    ];
    for (const [who, own, all, list, one, label] of matrix) {
      await test.step(label, async () => {
        await loginAsPerson(page, who);
        expect(await embedding(page, 'OWN'), `${label}: the "only own results" embedding`).toBe(own);
        expect(await embedding(page, 'ALL'), `${label}: the "all results" embedding`).toBe(all);
        expect(await roster(page, contextId('CM_OWN')), `${label}: the roster the server hands out`).toBe(list);
        expect(await detail(page, 'B', contextId('CM_OWN')), `${label}: Beta's snapshot from the server`).toBe(one);
      });
    }
  });

  test('P3: the teacher without editing rights sees a learner\'s path and cannot change it (#39)', { tag: ['@story:P3'] }, async ({ page }) => {
    const node = (id: string) => page.locator(`[data-testid="learningpath-node-${id}"]`);
    const openBeta = async (who: string) => {
      await loginAsPerson(page, who);
      expect(await embedding(page, 'OWN')).toContain(`${BETA_ROW} | link`);
      await page.locator('#adele-userlist-table tbody tr').filter({ hasText: BETA }).getByRole('link').first().click();
      await expect(node('dndnode_3')).toHaveAttribute('data-status', 'accessible', { timeout: 60_000 });
    };

    await test.step("with editing rights: Beta's path, with the controls that change it", async () => {
      await openBeta('TEACHER');
      await expect(node('dndnode_3').getByRole('button', { name: 'Master checkboxes' })).toHaveCount(1);
      await expect(page.getByRole('button', { name: 'Save', exact: true })).toBeVisible();
    });

    await test.step("without editing rights: the same path, the same states - and nothing to change it with", async () => {
      await openBeta('TUTOR');
      for (const [nodeid, status] of [['dndnode_1', 'completed'], ['dndnode_2', 'completed'], ['dndnode_3', 'accessible']]) {
        await expect(node(nodeid), `Beta's ${nodeid}`).toHaveAttribute('data-status', status);
      }
      await expect(page.getByRole('button', { name: 'Master checkboxes' })).toHaveCount(0);
      await expect(page.getByLabel('Master completion checkbox')).toHaveCount(0);
      await expect(page.getByLabel('Master access checkbox')).toHaveCount(0);
      await expect(page.getByRole('button', { name: 'Save', exact: true })).toHaveCount(0);
    });
  });

  test('P4: a participant asks for foreign data directly', { tag: ['@story:P4'] }, async ({ page }) => {
    const denied = 'denied (nopermissions)';

    await test.step('the foreign row: whatever context the request names, never Beta', async () => {
      await loginAsPerson(page, 'A');
      // The embedding itself and its course: the own row.
      expect(await roster(page, contextId('CM_OWN')), 'the embedding').toBe(ALPHA);
      expect(await roster(page, contextId('HOST')), 'the embedding course: the strictest setting wins').toBe(ALPHA);
      // The userid parameter is client input and must not select a row.
      expect(await roster(page, contextId('CM_OWN'), userId('B')), 'userid = Beta').toBe(ALPHA);
      // A node course of the path - ADELE enrolled the learner there, and it
      // embeds nothing: no roster. (Used to return everybody.)
      expect(await roster(page, contextId('NODE')), 'a node course').toBe(denied);
      // A course the learner is not in, and no course at all.
      expect(await roster(page, contextId('OTHER')), 'a foreign course').toBe(denied);
      expect(await roster(page, 1), 'the system context').toBe(denied);
    });

    await test.step("the foreign detail view: Beta's snapshot is refused in every context", async () => {
      for (const name of ['CM_OWN', 'CM_ALL', 'HOST', 'NODE', 'OTHER']) {
        expect(await detail(page, 'B', contextId(name)), `Beta's snapshot via ${name}`).toBe(denied);
      }
      expect(await detail(page, 'B', 1), "Beta's snapshot via the system context").toBe(denied);
      // The own snapshot stays readable - the refusal is about Beta, not about the call.
      expect(await detail(page, 'A', contextId('CM_OWN'))).toBe(ALPHA);
    });

    await test.step("the direct URL of Beta's detail view shows the own path, not Beta's", async () => {
      await page.goto(`/mod/adele/view.php?id=${fixture('ADELE_FIXTURE_P_CM_OWN')}` +
        `#/learningpaths/edit/${pathId()}/${userId('B')}`);
      await expect(page.locator('#adele-userlist-table tbody tr').first()).toBeVisible({ timeout: 60_000 });
      await page.waitForLoadState('networkidle');
      await expect(page.locator('#region-main')).not.toContainText(BETA);
      // Alpha's states (T02 merely accessible), not Beta's (T02 completed).
      await expect(page.locator('[data-testid="learningpath-node-dndnode_2"]'))
        .toHaveAttribute('data-status', 'accessible');
      expect(await participantList(page)).toEqual([ALPHA_ROW]);
    });

    await test.step('the editor does not let a participant in either', async () => {
      await page.goto(`/local/adele/index.php#/learningpaths/edit/${pathId()}/${userId('B')}`);
      await page.waitForLoadState('networkidle');
      await expect(page.locator('body')).not.toContainText(BETA);
      await expect(page.locator('[data-testid^="learningpath-node-"]')).toHaveCount(0);
    });

    await test.step("nobody without a right here changes Beta's path", async () => {
      // What a teacher's save sends: the learner's nodes, here with a master
      // completion on node 3. Built from Beta's real snapshot, so a call that
      // got through would do exactly what the path view does.
      await loginAsPerson(page, 'ADMIN');
      const snapshot = await callService(page, 'local_adele_get_user_path_relation', {
        learningpathid: pathId(),
        userpathid: userId('B'),
        contextid: contextId('CM_OWN'),
      });
      const nodes = JSON.parse((snapshot.data as { json: string }).json).tree.nodes as
        { id: string; data: { completion?: { master?: Record<string, unknown> } } }[];
      for (const node of nodes) {
        if (node.id === 'dndnode_3') {
          node.data.completion = { ...(node.data.completion || {}) };
          node.data.completion.master = { ...(node.data.completion.master || {}), completion: true };
        }
      }
      const params = JSON.stringify({ route: { learningpathId: pathId(), userId: userId('B') }, nodes });
      for (const [who, context, label] of [
        ['A', 'CM_OWN', 'a participant, in the embedding'],
        // Used to succeed (#39): seeing is not changing.
        ['TUTOR', 'CM_OWN', 'a teacher of this course without editing rights'],
        ['COLLABORATOR', 'CM_OWN', 'a collaborator of the path who is a participant in the course'],
        ['ASSISTANT', 'CM_OWN', 'an assistant who edits another path'],
        // Used to succeed: managing paths is no right over learners.
        ['MANAGER', 'CM_OWN', 'an ADELE manager, through the embedding'],
        ['MANAGER', 'SYSTEM', 'an ADELE manager, outside any course'],
        ['OUTSIDER', 'OTHER', 'a participant of another course, through that course'],
        // Used to succeed: Beta's node 3 was completed by a teacher of a course
        // that has nothing to do with the path.
        ['FOREIGN', 'OTHER', 'a teacher of another course, through that course'],
      ] as const) {
        await loginAsPerson(page, who);
        const answer = await callService(page, 'local_adele_save_user_path_relation', {
          learningpathid: pathId(),
          userid: userId('B'),
          params,
          contextid: context === 'SYSTEM' ? 1 : contextId(context),
        });
        expect(answer.error, `${label}: the change must be refused`).toBe('nopermissions');
      }
      // And nothing happened: Beta still has two of three, node 3 merely open.
      drainTaskQueue();
      await loginAsPerson(page, 'B');
      expect(await nodeStates(page, 'E2E P own results')).toBe('completed completed accessible');
      expect(await embedding(page, 'OWN')).toBe(`participant view: ${BETA_ROW}`);
    });

    await test.step('the teacher of this course has no say over the learner of the other course (#588)', async () => {
      await loginAsPerson(page, 'TEACHER');
      expect(await detail(page, 'B', contextId('CM_OWN')), 'a learner of the own course').toBe(BETA);
      expect(await detail(page, 'C', contextId('CM_OWN')), "Gamma's snapshot").toBe(denied);
      const answer = await callService(page, 'local_adele_save_user_path_relation', {
        learningpathid: pathId(),
        userid: userId('TEACHER'),
        params: JSON.stringify({ route: { learningpathId: pathId(), userId: userId('C') }, nodes: [] }),
        contextid: contextId('CM_OWN'),
      });
      expect(answer.error, "changing Gamma's path must be refused").toBe('nopermissions');
    });

    await test.step('people of another course: its context opens nothing of this path', async () => {
      // Both used to work: the context of ANY course somebody belonged to
      // was accepted for ANY path.
      await loginAsPerson(page, 'OUTSIDER');
      expect(await roster(page, contextId('OTHER')), 'participant of another course, own course context').toBe(denied);
      expect(await detail(page, 'B', contextId('OTHER'))).toBe(denied);
      await loginAsPerson(page, 'FOREIGN');
      expect(await roster(page, contextId('OTHER')), 'teacher of another course, own course context').toBe(denied);
      expect(await detail(page, 'B', contextId('OTHER')), "teacher of another course, Beta's snapshot").toBe(denied);
    });
  });
});

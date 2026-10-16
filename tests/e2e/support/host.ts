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
 * The activity form of mod_adele, driven the way a teacher drives it:
 * embedding a path into a course, and changing an embedding that exists.
 *
 * Shared by host-course-participants.spec.ts (chains H1-H3: the settings at
 * creation) and withdrawn-settings.spec.ts (the same settings changed
 * afterwards - E2E issue, addendum of 2026-10-13).
 *
 * @copyright   2026 Wunderbyte GmbH
 * @copyright   2026 Ralf Erlebach
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { execFileSync } from 'node:child_process';
import { expect, Page } from '@playwright/test';
import { fixture, referencePath } from './fixtures';

/** The participant sources, as the form labels them. */
export const SOURCE_THIS = 'for people enrolled in this course';
export const SOURCE_START = 'for people enrolled in a starting node';
export const SOURCE_ANY = 'for people enrolled in any course of the learning path';

/** The host enrolment modes, as the form labels them. */
export const MODE_VISIBLE = 'automatic (with full access for participants)';
export const MODE_HIDDEN = 'hidden (as a suspended enrolment)';
export const MODE_NONE = 'no enrolment at all';

/** "Participant results", as the form labels them. */
export const RESULTS_OWN = 'Only own results';
export const RESULTS_ALL = 'Results of all other participants';

/**
 * Pick one entry in a Moodle autocomplete field.
 *
 * @param page The page showing the form.
 * @param label The field's label.
 * @param entry The exact text of the entry to pick.
 */
export async function chooseInAutocomplete(page: Page, label: string, entry: string): Promise<void> {
  const input = page.getByRole('combobox', { name: label });
  // Only in THIS field's suggestion list: Moodle links it to the input via
  // aria-controls. A page-wide search also finds the entries already
  // selected - in this field and in the other autocomplete - which are
  // options too.
  const listid = await input.getAttribute('aria-controls');
  const option = (listid ? page.locator(`[id="${listid}"]`) : page)
    .getByRole('option', { name: entry, exact: true }).first();
  // What the form will actually submit: the hidden <select> that Moodle's
  // autocomplete fills. A click on a suggestion is not proof - under load a
  // choice was lost (the second participant source never reached the form,
  // and the chain failed later for a reason that looked like the product's).
  const field = autocompleteSelect(page, entry);
  const chosen = async (): Promise<boolean> =>
    (await field.locator('option:checked').allInnerTexts()).some((text) => text.trim() === entry);

  for (let attempt = 0; attempt < 3 && !(await chosen()); attempt++) {
    // Typed like a person: Moodle opens its list from its own key handlers,
    // which a one-shot fill() can miss when the page is busy.
    await input.click();
    await input.fill('');
    await input.pressSequentially(entry.slice(0, 20), { delay: 20 });
    await option.waitFor({ state: 'visible', timeout: 5_000 }).catch(() => undefined);
    if (!(await option.isVisible())) {
      await input.press('ArrowDown');
      await option.waitFor({ state: 'visible', timeout: 5_000 }).catch(() => undefined);
    }
    if (await option.isVisible()) {
      await option.click();
    }
    // Leave the field with Tab: it closes the suggestion list (otherwise the
    // next click on the page only closes the list) and keeps the choice.
    await input.press('Tab');
    await expect.poll(chosen, { timeout: 5_000 }).toBe(true).catch(() => undefined);
  }
  expect(await chosen(), `"${entry}" must be selected in "${label}"`).toBe(true);
}

/**
 * The hidden <select> behind a Moodle autocomplete that offers this entry.
 *
 * @param page The page showing the form.
 * @param entry The text of one of its options.
 */
function autocompleteSelect(page: Page, entry: string) {
  return page.locator('form.mform select').filter({
    has: page.locator('option', { hasText: entry }),
  }).first();
}

/**
 * Take one entry out of a Moodle autocomplete field, as a person does: by
 * clicking the chosen entry's badge, which removes it.
 *
 * @param page The page showing the form.
 * @param entry The exact text of the entry to remove.
 */
export async function removeFromAutocomplete(page: Page, entry: string): Promise<void> {
  const field = autocompleteSelect(page, entry);
  const chosen = async (): Promise<boolean> =>
    (await field.locator('option:checked').allInnerTexts()).some((text) => text.trim() === entry);
  expect(await chosen(), `precondition: "${entry}" is selected`).toBe(true);
  // The badges of the chosen entries; Moodle gives each the role "option"
  // inside the selection list next to the field.
  const badge = page.locator('.form-autocomplete-selection [role="option"]').filter({ hasText: entry }).first();
  for (let attempt = 0; attempt < 3 && (await chosen()); attempt++) {
    await badge.click();
    await expect.poll(chosen, { timeout: 5_000 }).toBe(false).catch(() => undefined);
  }
  expect(await chosen(), `"${entry}" must no longer be selected`).toBe(false);
}

/**
 * Embed the reference path into a host course with the given sources and,
 * where the form offers it, host enrolment mode.
 *
 * @param page The page, logged in as a teacher or admin.
 * @param hostcourse The host course id.
 * @param name The activity name.
 * @param sources The participant sources, as their labels.
 * @param mode The host enrolment mode's label, if it is to be changed.
 * @param pathname The path to embed; the reference path unless said otherwise.
 */
export async function embedWith(
  page: Page, hostcourse: string, name: string, sources: string[], mode?: string, pathname: string = referencePath.name
): Promise<void> {
  await page.goto(`/course/modedit.php?add=adele&course=${hostcourse}&section=0`);
  await page.locator('#id_name').fill(name);
  await chooseInAutocomplete(page, 'Chosen Learning Path', pathname);
  for (const source of sources) {
    await chooseInAutocomplete(page, 'Learning path enrolment', source);
  }
  if (mode) {
    await chooseMode(page, mode);
  }
  await page.getByRole('button', { name: /Save and return to course|Speichern und zum Kurs/i }).click();
  // In the main region: the course index may link the activity a second time.
  await expect(page.getByRole('main').getByRole('link', { name: name })).toBeVisible();
}

/**
 * Choose the host enrolment mode in the open form.
 *
 * @param page The page showing the form.
 * @param mode The mode's label.
 */
async function chooseMode(page: Page, mode: string): Promise<void> {
  // A plain select, shown only for the sources "starting node" and "any
  // course" - selecting by its visible label proves it is shown.
  // By role: the label alone also matches the help button beside the field.
  await page.getByRole('combobox', { name: 'Enrolment in this course', exact: true }).selectOption({ label: mode });
}

/**
 * The course module id of an embedding, read from the course page as a
 * teacher sees it.
 *
 * @param page The page, logged in as a teacher or admin.
 * @param hostcourse The course.
 * @param name The activity's name.
 */
export async function embeddingCmid(page: Page, hostcourse: string, name: string): Promise<string> {
  await page.goto(`/course/view.php?id=${hostcourse}`);
  // By target and visible text: the link's accessible name carries more than
  // the activity's name (Moodle appends the activity type), so an exact name
  // match finds nothing.
  const href = await page.getByRole('main').locator('a[href*="/mod/adele/view.php?id="]')
    .filter({ hasText: name }).first().getAttribute('href');
  const cmid = /[?&]id=(\d+)/.exec(href || '')?.[1];
  expect(cmid, `the course page must link the activity "${name}"`).toBeTruthy();
  return cmid as string;
}

/** What to change in an existing embedding; anything left out stays. */
export interface EmbeddingChange {
  mode?: string;
  addSources?: string[];
  removeSources?: string[];
  results?: string;
}

/**
 * Change an existing embedding through Moodle's own settings form and save
 * it - the way a teacher does, never by writing to the database: the form's
 * save is what queues the reconcile this whole file is about.
 *
 * @param page The page, logged in as a teacher or admin.
 * @param cmid The embedding's course module id.
 * @param change What to change.
 */
export async function editEmbedding(page: Page, cmid: string, change: EmbeddingChange): Promise<void> {
  await page.goto(`/course/modedit.php?update=${cmid}`);
  await expect(page.locator('#id_name')).toBeVisible();
  for (const source of change.removeSources || []) {
    await removeFromAutocomplete(page, source);
  }
  for (const source of change.addSources || []) {
    await chooseInAutocomplete(page, 'Learning path enrolment', source);
  }
  if (change.mode) {
    await chooseMode(page, change.mode);
  }
  if (change.results) {
    await page.getByRole('combobox', { name: 'Participant results', exact: true }).selectOption({ label: change.results });
  }
  await page.getByRole('button', { name: /Save and return to course|Speichern und zum Kurs/i }).click();
  // Saved: Moodle leaves the form for the course page. A validation error
  // would keep the form open instead.
  await expect(page).toHaveURL(/\/course\/view\.php/);
}

/**
 * What the stored embedding says, read on the server: the setting is
 * persisted, not merely shown in a form.
 *
 * @param cmid The embedding's course module id.
 */
export function storedEmbedding(cmid: string): { participantslist: string; hostenrolmentmode: string; userlist: number } {
  const out = execFileSync('php', ['-r', `
    define('CLI_SCRIPT', true);
    require('config.php');
    $cm = get_coursemodule_from_id('adele', (int) $argv[1], 0, false, MUST_EXIST);
    $row = $DB->get_record('adele', ['id' => $cm->instance], 'participantslist, hostenrolmentmode, userlist', MUST_EXIST);
    echo json_encode(['participantslist' => (string) $row->participantslist,
      'hostenrolmentmode' => (string) $row->hostenrolmentmode, 'userlist' => (int) $row->userlist]);`,
  '--', cmid], { cwd: fixture('ADELE_MOODLE_ROOT'), encoding: 'utf8' });
  return JSON.parse(out.trim().split('\n').pop() || '{}');
}

/**
 * The row of a person on a course's participants page, as an admin sees it.
 *
 * @param page The page, logged in as admin.
 * @param courseid The course.
 * @param fullname The person's full name.
 */
export async function participantRow(page: Page, courseid: string, fullname: string) {
  await page.goto(`/user/index.php?id=${courseid}&perpage=5000`);
  return page.locator('table#participants tbody tr').filter({ hasText: fullname });
}

/**
 * Whether a person is on a learning path, read on the server.
 *
 * @param username The person.
 * @param pathid The path.
 */
export function onPath(username: string, pathid: string): boolean {
  const out = execFileSync('php', ['-r', `
    define('CLI_SCRIPT', true);
    require('config.php');
    $user = $DB->get_record('user', ['username' => $argv[1]], 'id', MUST_EXIST);
    echo $DB->record_exists('local_adele_path_user', ['learning_path_id' => (int) $argv[2], 'user_id' => $user->id]) ? 'yes' : 'no';`,
  '--', username, pathid], { cwd: fixture('ADELE_MOODLE_ROOT'), encoding: 'utf8' });
  return out.trim().endsWith('yes');
}

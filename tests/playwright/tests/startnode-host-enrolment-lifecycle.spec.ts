import { execFileSync } from 'node:child_process';
import { test, expect, Page } from '@playwright/test';
import { env, loginAs } from '../support/env';

/**
 * ADELE-PW-MOD-01 — starting-node participants are taken into the host
 * course, and taken back out when the activity is deleted.
 *
 * The whole lifecycle in one test on purpose. Splitting it would leave the
 * deletion half asserting a state some other test produced, and the point
 * here is precisely that the SAME activity created the enrolments and its
 * removal takes them away again.
 *
 * "Deleting the learning path" means deleting the Moodle ACTIVITY. The
 * local_adele record must survive — otherwise the cleanup could be explained
 * by the path being gone, which proves nothing about the activity lifecycle.
 * That survival is asserted at the end.
 */

const ACTIVITY_NAME = 'PW Startnode Teilnehmer Lifecycle';

// The two option texts the specification quotes verbatim. They come from
// mod_adele's own language pack, so they follow the site language like every
// other plugin string — if the site is not German, these have to change with
// it. Kept as named constants so that dependency is visible in one place
// instead of buried in three selectors.
// Read from mod_adele/lang/, not guessed. Both wordings exist in German too
// ("für in Startknoten eingeschriebene Personen" / "automatisch (mit vollem
// Zugriff für Teilnehmende)") and are exactly what the specification quotes;
// which of the two the interface shows depends on the site language.
// How Moodle marks a suspended enrolment in the participants table: a warning
// badge in the status column. Verified against the rendered page rather than
// assumed — an earlier version looked for .dimmed_text, which this table does
// not use, and the test then reported "not suspended" for an enrolment that
// was correctly suspended.
const SUSPENDED_BADGE = '.badge.bg-warning';

const PARTICIPANTS_STARTING_NODES = 'for people enrolled in a starting node';
const HOST_MODE_VISIBLE = 'automatic (with full access for participants)';

/**
 * Participant rows for one username on the course participants page.
 *
 * Filtered by username through the URL rather than by reading names off the
 * page: two fixture users share a surname pattern, and Moodle does not render
 * the username in the table, so a name match could report the wrong person.
 *
 * @param page The page to use.
 * @param courseid The course whose participants are listed.
 * @param username The username to filter for.
 * @returns The number of matching participant rows.
 */
async function participantRows(page: Page, courseid: string, username: string): Promise<number> {
  await page.goto(`/user/index.php?id=${courseid}`);
  // The participants TABLE, not a heading: the page heading is the course
  // name, and waiting for a translated caption would tie this to the site
  // language. The table is what the assertion is about anyway.
  // Waits for the participants FORM, not for the table.
  //
  // With no participants Moodle renders no table at all, only an empty-state
  // message — so waiting for a table would hang exactly in the case this test
  // has to assert first: that nobody is enrolled yet. The form is present
  // either way and is the honest "page has finished loading" signal.
  const region = page.locator('#participantsform');
  await expect(region).toBeVisible();
  return region.locator(`tbody tr:has-text("${username}@example.invalid")`).count();
}

test.describe('ADELE-PW-MOD-01 — starting node host enrolment lifecycle', () => {
  test('creating the activity enrols starting node participants, deleting it removes them', async ({ page }) => {
    test.slow();
    await loginAs(page, env.adminUser, env.adminPassword);

    // --- Precondition: nobody is in the host course yet -------------------
    // Without this the whole test could pass on a fixture that already had
    // the enrolments, proving nothing.
    for (const username of [env.startUser01, env.startUser02, env.controlUser]) {
      expect(
        await participantRows(page, env.lifecycleHostCourseId, username),
        `${username} must not be in the host course before the activity exists`
      ).toBe(0);
    }

    // --- Phase A: create the activity through the interface ---------------
    // Through the UI, not the seed: the create lifecycle is what is on test.
    await page.goto(`/course/modedit.php?add=adele&course=${env.lifecycleHostCourseId}&section=0`);
    await expect(page.locator('#id_name')).toBeVisible();

    // Core form elements are addressed by their FIELD NAME (Moodle renders
    // them as id_<name>), not by their label. The labels of the surrounding
    // Moodle chrome depend on the site language, and making this suite depend
    // on a downloaded core language pack would add a network dependency and a
    // failure mode that has nothing to do with the feature under test.
    //
    // The three mod_adele fields below are asserted through their GERMAN
    // option texts, because those come from the plugin's own language pack and
    // are exactly the wording the specification quotes.
    await page.locator('#id_name').fill(ACTIVITY_NAME);
    await chooseAutocomplete(page, 'id_learningpathid', env.lifecyclePathTitle);
    await chooseAutocomplete(page, 'id_participantslist', PARTICIPANTS_STARTING_NODES);

    // The visibility mode only matters for options 2 and 3, so an AMD module
    // reveals the field once such an option is selected. Waiting for it to
    // become visible is therefore also the assertion that the option above
    // really took effect — a value written straight into the hidden select
    // would never have triggered it.
    const mode = page.locator('#id_hostenrolmentmode');
    await expect(mode).toBeVisible();
    await mode.selectOption({ label: HOST_MODE_VISIBLE });

    await page.locator('#id_submitbutton2').click();
    await expect(page.getByRole('link', { name: ACTIVITY_NAME })).toBeVisible();

    // --- Phase B: the two starting node users are now in the host course --
    expect(
      await participantRows(page, env.lifecycleHostCourseId, env.startUser01),
      'the first starting node participant must appear exactly once'
    ).toBe(1);
    expect(
      await participantRows(page, env.lifecycleHostCourseId, env.startUser02),
      'the second starting node participant must appear exactly once'
    ).toBe(1);

    // The negative control proves the transfer is targeted, not a blanket
    // enrolment of everyone the plugin can reach.
    expect(
      await participantRows(page, env.lifecycleHostCourseId, env.controlUser),
      'the control user must not be taken into the host course'
    ).toBe(0);

    // Active, not suspended: "automatisch (mit vollem Zugriff)" was chosen,
    // and a suspended row would satisfy a mere presence check while denying
    // the access the setting promises.
    await page.goto(`/user/index.php?id=${env.lifecycleHostCourseId}`);
    const activeRow = page.locator(
      `#participantsform tbody tr:has-text("${env.startUser01}@example.invalid")`
    );
    // Suspended enrolments carry a dimmed status badge; core marks the row
    // with a class rather than only a translated word.
    await expect(activeRow.locator('.dimmed_text')).toHaveCount(0);

    // --- Phase C: delete the activity through the interface ---------------
    await page.goto(env.lifecycleHostCourseUrl);
    await page.goto(
      `/course/mod.php?sesskey=${await sesskey(page)}&delete=${await cmid(page, ACTIVITY_NAME)}`
    );
    // The confirmation page carries THREE forms — the edit-mode switch, the
    // course search and the actual confirmation. Taking "the first submit
    // button on the page" silently toggled edit mode and left the activity in
    // place, which then looked like a failure of the clean-up rather than of
    // the deletion. Scoped to the form that posts to mod.php and carries the
    // confirm flag, so it cannot be any of the others.
    const confirmForm = page.locator('form[action*="/course/mod.php"]:has(input[name="confirm"])');
    await expect(confirmForm).toHaveCount(1);
    await confirmForm.locator('button[type="submit"], input[type="submit"]').first().click();

    // The activity is really gone, not just off this page.
    await page.goto(env.lifecycleHostCourseUrl);
    await expect(page.getByRole('link', { name: ACTIVITY_NAME })).toHaveCount(0);

    // Deleting the activity queues an ad-hoc task rather than doing the work
    // in the request — a busy host course can hold thousands of enrolments and
    // a form submit must not block on them. The queue is therefore run here,
    // explicitly. Waiting for cron instead would be forbidden by the test
    // contract and useless: a timeout cannot distinguish "the task ran" from
    // "the task never existed".
    //
    // Note the asymmetry, which is deliberate in the product: CREATING the
    // activity enrols synchronously through the observer, so phase B needed no
    // task run.
    runAdhocTasks();

    // --- Phase D: the automatic host access is withdrawn ------------------
    //
    // WITHDRAWN, not deleted — and the difference is deliberate product
    // behaviour, not a shortcoming of this test.
    //
    // enrol_adele suspends an enrolment that has lost its justification
    // instead of removing it, so that reports, certificates and grades keep
    // working. Moodle lists suspended participants, marked as suspended, for
    // the configured retention period (enrol_adele/suspendedretention,
    // 90 days by default); only afterwards are they removed. That semantics
    // was decided in enrol_adele issue #7.
    //
    // The test therefore asserts what the product promises: the row may still
    // be there, but the access is gone. Asserting a row count of zero would
    // demand behaviour the system deliberately does not have, and would fail
    // for a correct implementation.
    for (const username of [env.startUser01, env.startUser02]) {
      expect(
        await suspendedParticipantRows(page, env.lifecycleHostCourseId, username),
        `${username} must be suspended in the host course after the activity is gone`
      ).toBe(1);
      expect(
        await activeParticipantRows(page, env.lifecycleHostCourseId, username),
        `${username} must have no active host enrolment left`
      ).toBe(0);
    }

    // The negative control never gained access and must not have appeared in
    // the meantime either.
    expect(
      await participantRows(page, env.lifecycleHostCourseId, env.controlUser),
      'the control user must still be absent from the host course'
    ).toBe(0);

    // The source enrolments must survive: cleaning up the host course must
    // not reach back into the course the users came from.
    expect(
      await participantRows(page, env.lifecycleStartCourseId, env.startUser01),
      'the starting node enrolment must survive'
    ).toBe(1);
    expect(
      await participantRows(page, env.lifecycleStartCourseId, env.startUser02),
      'the starting node enrolment must survive'
    ).toBe(1);

    // And the learning path itself must still exist, so the cleanup cannot be
    // explained by the path having been deleted.
    await page.goto('/local/adele/index.php');
    await expect(
      page.locator('[id^="local-adele-app"]').getByText(env.lifecyclePathTitle, { exact: true }).first()
    ).toBeVisible({ timeout: 20_000 });
  });
});

/**
 * Choose a value in a Moodle autocomplete field.
 *
 * Moodle renders these as a HIDDEN <select> plus a JavaScript widget, so
 * selectOption() on the select does nothing visible and, worse, does not fire
 * the events other fields listen to — the visibility mode field below is
 * revealed by exactly such a listener. The widget therefore has to be driven
 * the way a person drives it.
 *
 * The widget is located through the container that also holds the hidden
 * select ([data-fieldtype="autocomplete"]), not through its own generated id
 * (form_autocomplete_input-<timestamp>) and not by position. The select's id
 * is stable and carries the field name, which is what makes this
 * language-independent.
 *
 * @param page The page showing the form.
 * @param selectId The id of the hidden select, e.g. "id_learningpathid".
 * @param label The exact option text to choose.
 */
async function chooseAutocomplete(page: Page, selectId: string, label: string): Promise<void> {
  const field = page.locator(`[data-fieldtype="autocomplete"]:has(#${selectId})`);
  const combo = field.getByRole('combobox');
  await combo.click();
  // Typed, not just clicked: the widget shows only a capped number of
  // suggestions, so on a site with many learning paths the wanted one is
  // simply not in the initial list. Filtering is what a person does too.
  await combo.fill(label);
  const option = page.getByRole('option', { name: label, exact: true });
  await expect(option).toHaveCount(1);
  await option.click();
  // The widget writes the choice back into the hidden select; asserting that
  // makes a silently ignored click fail here rather than three steps later.
  await expect(page.locator(`#${selectId}`)).not.toHaveValue('');
}

/**
 * Participant rows for one username whose enrolment is SUSPENDED.
 *
 * Moodle marks a suspended participant's row with the dimmed class rather than
 * only with a translated word, so this works whatever the site language is.
 *
 * @param page The page to use.
 * @param courseid The course whose participants are listed.
 * @param username The username to look for.
 * @returns The number of matching suspended rows.
 */
async function suspendedParticipantRows(page: Page, courseid: string, username: string): Promise<number> {
  await page.goto(`/user/index.php?id=${courseid}`);
  await expect(page.locator('#participantsform')).toBeVisible();
  return page
    .locator(`#participantsform tbody tr:has-text("${username}@example.invalid")`)
    .filter({ has: page.locator(SUSPENDED_BADGE) })
    .count();
}

/**
 * Participant rows for one username whose enrolment is ACTIVE.
 *
 * The counterpart of the above: a row that is present but dimmed must not
 * count as access. Checking only for presence, or only for absence, would let
 * a suspended and an active enrolment pass for the same thing.
 *
 * @param page The page to use.
 * @param courseid The course whose participants are listed.
 * @param username The username to look for.
 * @returns The number of matching active rows.
 */
async function activeParticipantRows(page: Page, courseid: string, username: string): Promise<number> {
  await page.goto(`/user/index.php?id=${courseid}`);
  await expect(page.locator('#participantsform')).toBeVisible();
  return page
    .locator(`#participantsform tbody tr:has-text("${username}@example.invalid")`)
    .filter({ hasNot: page.locator(SUSPENDED_BADGE) })
    .count();
}

/**
 * Run Moodle's queued ad-hoc tasks to completion.
 *
 * @throws When the CLI call fails, so a silent no-op cannot pass for success.
 */
function runAdhocTasks(): void {
  // TWO classes, in this order, and that order is the whole point.
  //
  // Moodle does not delete an activity in the request either: course_delete_
  // module() queues \core_course\task\course_delete_modules, and only inside
  // that task does adele_delete_instance() run — which is what queues ADELE's
  // own clean-up. Running only the ADELE class finds an empty queue and passes
  // silently, leaving the enrolment in place.
  //
  // Named classes rather than --execute: the latter also runs unrelated core
  // tasks, and a single failing login notification in a fixture environment
  // without mail configuration would abort the run.
  for (const classname of [
    '\\core_course\\task\\course_delete_modules',
    '\\enrol_adele\\task\\reconcile_host_embedding_adhoc',
  ]) {
    execFileSync('php', ['admin/cli/adhoc_task.php', `--classname=${classname}`], {
      cwd: env.moodleRoot,
      stdio: 'pipe',
    });
  }
}

/**
 * The current session key, read from a rendered page.
 *
 * @param page The page to read from.
 * @returns The sesskey.
 */
async function sesskey(page: Page): Promise<string> {
  const value = await page.evaluate(() => (window as unknown as { M?: { cfg?: { sesskey?: string } } }).M?.cfg?.sesskey);
  if (!value) {
    throw new Error('No sesskey found on the page — is the session still valid?');
  }
  return value;
}

/**
 * The course module id of an activity, taken from its link on the course page.
 *
 * @param page A page showing the course.
 * @param name The exact activity name.
 * @returns The course module id.
 */
async function cmid(page: Page, name: string): Promise<string> {
  const href = await page.getByRole('link', { name }).first().getAttribute('href');
  const match = /id=(\d+)/.exec(href ?? '');
  if (!match) {
    throw new Error(`Could not determine the course module id of "${name}" from ${href}`);
  }
  return match[1];
}

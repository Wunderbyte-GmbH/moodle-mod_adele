import { test, expect } from '@playwright/test';
import { env, loginAsAdmin } from '../support/env';

/**
 * Smoke coverage for the ADELE activity in a host course.
 *
 * The activity is the only place where a learning path is embedded into an
 * ordinary course, and the embedding is what drives host course enrolment.
 * PHPUnit covers the derivation; this suite checks that the activity actually
 * renders and that the course page links to it.
 *
 * Deliberately minimal — the participant list and the subscription options
 * come later.
 */
test.describe('ADELE activity', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('the activity appears on its host course page', async ({ page }) => {
    await page.goto(`/course/view.php?id=${env.courseId}`);

    await expect(page.getByRole('link', { name: /Playwright-Lernpfadaktivität/ })).toBeVisible();
  });

  test('the activity view renders the embedded learning path', async ({ page }) => {
    await page.goto(`/mod/adele/view.php?id=${env.cmid}`);

    await expect(page.getByRole('heading', { name: /Playwright-Lernpfadaktivität/ })).toBeVisible();
    // The activity embeds local_adele's Vue application; if the embedding is
    // broken the page still renders, just without the mount point.
    await expect(page.locator('[id^="local-adele-app"]')).toBeVisible();
  });

  test('the activity view reports no PHP or JavaScript error', async ({ page }) => {
    const consoleErrors: string[] = [];
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.goto(`/mod/adele/view.php?id=${env.cmid}`);
    await expect(page.locator('.errorbox')).toHaveCount(0);
    expect(consoleErrors, `Uncaught JavaScript errors: ${consoleErrors.join(' | ')}`).toEqual([]);
  });
});

import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright configuration for mod_adele.
 *
 * The suite talks to a live Moodle site, so the base URL is supplied from
 * outside rather than guessed. seed.php prints it along with the credentials
 * and the fixture data; support/env.ts fails loudly when a variable is
 * missing, because a browser test silently pointed at the wrong site is worse
 * than one that does not start.
 */
export default defineConfig({
  testDir: './tests',
  // A browser run is slower than a unit test but must not hang a CI job.
  timeout: 60_000,
  expect: { timeout: 10_000 },
  // Serial by default: the suite shares one seeded site, and parallel workers
  // logging in and out of the same account fight over the session.
  workers: 1,
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : [['list']],
  use: {
    baseURL: process.env.ADELE_BASE_URL,
    // Evidence only for failures; a green run of a smoke suite needs none.
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    ignoreHTTPSErrors: true,
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
});

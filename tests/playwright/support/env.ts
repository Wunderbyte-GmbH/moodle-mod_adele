import { Page, expect } from '@playwright/test';

/**
 * Read a required environment variable.
 *
 * Throws rather than defaulting: every one of these comes from seed.php, and
 * a missing value means the seeding step did not run or its output was not
 * exported. Continuing with an empty string produces a browser test that
 * fails somewhere far from the real cause.
 */
export function required(name: string): string {
  const value = process.env[name];
  if (!value) {
    throw new Error(
      `Environment variable ${name} is not set. It is produced by tests/playwright/seed.php; ` +
      `check that the seeding step ran and that its output reached the job environment.`
    );
  }
  return value;
}

export const env = {
  get baseUrl() { return required('ADELE_BASE_URL'); },
  get adminUser() { return required('ADELE_ADMIN_USER'); },
  get adminPassword() { return required('ADELE_ADMIN_PASSWORD'); },
  get learningPathName() { return required('ADELE_LP_NAME'); },
  get courseId() { return required('ADELE_HOST_COURSE_ID'); },
  get cmid() { return required('ADELE_CMID'); },
};

/**
 * Authenticate the browser context as admin.
 *
 * Done over HTTP rather than by driving the login form, and that is a
 * correction of an earlier decision here. Driving the form looked appealing
 * as a free smoke test of the whole stack; in practice the page re-rendered
 * between filling the fields and submitting them, so Moodle received an empty
 * password and answered "Invalid login, please try again". Measured on this
 * environment: the form path failed in roughly three of four suite runs,
 * while the same credentials over HTTP succeeded eight times out of eight.
 *
 * A flaky step that tests nothing this suite is about is worse than no step,
 * so the login moved to the request API. page.context().request shares its
 * cookie jar with the context, so the page is authenticated afterwards.
 * Moodle's login form remains covered by core's own tests.
 */
export async function loginAsAdmin(page: Page): Promise<void> {
  const api = page.context().request;

  const form = await api.get('/login/index.php');
  const token = /name="logintoken" value="([^"]+)"/.exec(await form.text())?.[1];
  if (!token) {
    throw new Error('No login token found on /login/index.php — is this a Moodle site?');
  }

  const response = await api.post('/login/index.php', {
    form: {
      anchor: '',
      logintoken: token,
      username: env.adminUser,
      password: env.adminPassword,
    },
  });

  // A successful login redirects away from the login page; a rejected one
  // comes back to it with an error, so the final URL is the reliable signal.
  const landed = response.url();
  if (landed.includes('/login/index.php')) {
    throw new Error(`Login rejected for user "${env.adminUser}". Moodle stayed on ${landed}.`);
  }
}

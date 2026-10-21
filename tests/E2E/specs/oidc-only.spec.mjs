import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';
import { test, expect } from '@playwright/test';

const appDirectory = resolve(import.meta.dirname, '..', '.app-run');

function identityStatus() {
  return JSON.parse(execFileSync('php', ['bin/identity-status.php'], {
    cwd: appDirectory,
    env: process.env,
    encoding: 'utf8',
  }));
}

test('OIDC-only first login creates a profile; a later login reuses it', async ({ browser }) => {
  const firstSession = await browser.newContext({ ignoreHTTPSErrors: true });
  const page = await firstSession.newPage();

  await page.goto('/login');
  await page.getByRole('link', { name: 'Sign in with your organization' }).click();
  await expect(page).toHaveURL(/https:\/\/127\.0\.0\.1:8443\/interaction\//);
  await page.locator('input[name="login"]').fill('provider-user-1');
  await page.locator('input[name="password"]').fill('test-password');
  await page.getByRole('button', { name: 'Sign-in' }).click();

  const consent = page.getByRole('button', { name: 'Continue' });
  if (await consent.isVisible().catch(() => false)) {
    await consent.click();
  }

  await expect(page).toHaveURL('http://127.0.0.1:8000/auth/oidc/complete');
  await page.getByRole('button', { name: 'Confirm and create my account' }).click();
  await expect(page).toHaveURL('http://127.0.0.1:8000/');
  await page.goto('/test/whoami');
  const firstUserId = (await page.locator('body').innerText()).trim();
  expect(firstUserId).toMatch(/^[1-9][0-9]*$/);
  expect(identityStatus()).toEqual({ users: 1, identities: 1, linked_user_id: Number(firstUserId) });
  await firstSession.close();

  const secondSession = await browser.newContext({ ignoreHTTPSErrors: true });
  const again = await secondSession.newPage();
  await again.goto('/login');
  await again.getByRole('link', { name: 'Sign in with your organization' }).click();
  await again.locator('input[name="login"]').fill('provider-user-1');
  await again.locator('input[name="password"]').fill('test-password');
  await again.getByRole('button', { name: 'Sign-in' }).click();
  const secondConsent = again.getByRole('button', { name: 'Continue' });
  if (await secondConsent.isVisible().catch(() => false)) {
    await secondConsent.click();
  }
  await expect(again).toHaveURL('http://127.0.0.1:8000/');
  await again.goto('/test/whoami');
  expect((await again.locator('body').innerText()).trim()).toBe(firstUserId);
  expect(identityStatus()).toEqual({ users: 1, identities: 1, linked_user_id: Number(firstUserId) });
  await secondSession.close();
});

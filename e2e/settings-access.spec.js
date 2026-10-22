// Settings → Access control — the configurable door. Proves the page
// renders for an app admin, the Open-mode gate demands an explicit
// confirm, the allowlist pickers + group checkboxes are wired, and a
// save round-trips through the API. Restricted stays restricted at the
// end (restore-prior contract — never leave the lab door open).
const { test, expect } = require('@playwright/test');
const { staffPage } = require('./helpers/seed');

const hasStaff = !!(process.env.E2E_USER && (process.env.E2E_PASSWORD || process.env.E2E_PASS));
const PAGE = '/apps/bookingcheck/settings/access';

async function reqToken(page) {
	return page.evaluate(() =>
		(window.OC && window.OC.requestToken)
		|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
		|| '');
}

async function apiGet(page, path) {
	const base = new URL(page.url()).origin;
	const token = await reqToken(page);
	return page.request.get(`${base}${path}`, {
		headers: { requesttoken: token, 'OCS-APIRequest': 'true', Accept: 'application/json' },
	});
}

async function apiPut(page, path, form) {
	const base = new URL(page.url()).origin;
	const token = await reqToken(page);
	return page.request.put(`${base}${path}`, {
		form,
		headers: { requesttoken: token, 'OCS-APIRequest': 'true', Accept: 'application/json' },
	});
}

test.describe('settings access door', () => {
	test.skip(!hasStaff, 'E2E_USER/E2E_PASSWORD unset');

	let prior = null;
	test.beforeAll(async ({ browser }) => {
		const page = await staffPage(browser);
		await page.goto(PAGE, { waitUntil: 'domcontentloaded' });
		const res = await apiGet(page, '/apps/bookingcheck/api/settings/access');
		if (res.ok()) {
			prior = (await res.json()).data?.settings ?? null;
		}
		await page.context().close();
	});
	test.afterAll(async ({ browser }) => {
		if (!prior) return;
		const page = await staffPage(browser);
		await page.goto(PAGE, { waitUntil: 'domcontentloaded' });
		await apiPut(page, '/apps/bookingcheck/api/settings/access', {
			access_restriction_enabled: String(prior.access_restriction_enabled ?? '1'),
			access_app_admin_user_ids: JSON.stringify(prior.access_app_admin_user_ids ?? []),
			access_allowed_user_ids: JSON.stringify(prior.access_allowed_user_ids ?? []),
			access_allowed_group_ids: JSON.stringify(prior.access_allowed_group_ids ?? []),
		});
		await page.context().close();
	});

	test('access section renders pickers, groups and mode select', async ({ page }) => {
		await page.goto(PAGE, { waitUntil: 'domcontentloaded' });
		await expect(page.locator('#bck-settings-root')).toBeVisible();
		await expect(page.locator('#bck-access-mode')).toBeVisible();
		await expect(page.locator('#bck-app-admins-search')).toBeVisible();
		await expect(page.locator('#bck-allowed-users-search')).toBeVisible();
		// group list (bookingcheck_admin exists on the lab instance)
		await expect(page.locator('input[name="access_allowed_groups"]').first()).toBeVisible();
		// settings nav sidebar advertises the catalog sections
		await expect(page.locator('#bck-nav-settings')).toBeVisible();
	});

	test('open mode requires the explicit confirmation', async ({ page }) => {
		await page.goto(PAGE, { waitUntil: 'domcontentloaded' });
		const mode = page.locator('#bck-access-mode');
		const wrap = page.locator('#bck-access-open-confirm-wrap');
		await mode.selectOption('open');
		await expect(wrap).toBeVisible();
		// save without the tick → refused client-side, no PUT fires
		let putFired = false;
		page.on('request', (r) => {
			if (r.method() === 'PUT' && r.url().includes('/api/settings/access')) putFired = true;
		});
		await page.locator('.bck-settings-form[data-section="access"] button[type="submit"]').click();
		await expect(page.locator('.bck-settings-status')).not.toBeEmpty();
		expect(putFired).toBe(false);
		await mode.selectOption('restricted');
	});

	test('user search returns directory results', async ({ page }) => {
		await page.goto(PAGE, { waitUntil: 'domcontentloaded' });
		const input = page.locator('#bck-allowed-users-search');
		await input.fill('ad');
		const results = page.locator('#bck-allowed-users-results');
		await expect(results).toBeVisible({ timeout: 10_000 });
		await expect(results.locator('li[role="option"]').first()).toBeVisible();
	});

	test('restricted save round-trips through the API', async ({ page }) => {
		await page.goto(PAGE, { waitUntil: 'domcontentloaded' });
		// deterministic mode — persisted lab state may render either option
		await page.locator('#bck-access-mode').selectOption('restricted');
		const box = page.locator('input[name="access_allowed_groups"][value="bookingcheck_admin"]');
		await expect(box).toBeVisible();
		if (!(await box.isChecked())) await box.check();
		await page.locator('.bck-settings-form[data-section="access"] button[type="submit"]').click();
		await expect(page.locator('.bck-settings-status')).toContainText(/saved/i, { timeout: 10_000 });
		const res = await apiGet(page, '/apps/bookingcheck/api/settings/access');
		expect(res.ok()).toBe(true);
		const body = await res.json();
		expect(body.data.settings.access_allowed_group_ids).toContain('bookingcheck_admin');
		expect(body.data.settings.access_restriction_enabled).toBe('1');
	});

	test('ghost uid is rejected with a field error', async ({ page }) => {
		await page.goto(PAGE, { waitUntil: 'domcontentloaded' });
		const res = await apiPut(page, '/apps/bookingcheck/api/settings/access', {
			access_allowed_user_ids: JSON.stringify(['no_such_user_atlas_probe']),
		});
		expect(res.status()).toBe(400);
		const body = await res.json();
		expect(body.fields?.access_allowed_user_ids ?? body.data?.fields?.access_allowed_user_ids).toBeTruthy();
	});

	test('/settings redirects into the first catalog section', async ({ page }) => {
		await page.goto('/apps/bookingcheck/settings', { waitUntil: 'domcontentloaded' });
		await expect(page).toHaveURL(/\/settings\/access/);
	});

	test('anonymous settings API call is refused', async ({ browser }) => {
		const ctx = await browser.newContext({ storageState: undefined });
		const page = await ctx.newPage();
		const res = await page.request.get(
			`${process.env.E2E_BASE || 'http://127.0.0.1:8081'}/apps/bookingcheck/api/settings/access`);
		expect([401, 403]).toContain(res.status());
		await ctx.close();
	});
});

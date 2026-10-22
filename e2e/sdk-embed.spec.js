// SDK embed (NH-05) — real-browser contract test.
// A staff session seeds a free offering and installs the golden lab
// BCK2 license (honored only when BCK_ALLOW_VENDOR_KEY_OVERRIDE=1,
// which the lab compose sets). A GUEST browser then mounts
// <bck-booking> on a same-origin page and books through the real
// anonymous API — shadow DOM, real fetch, real order.
const { test, expect } = require('@playwright/test');
const { AxeBuilder } = require('@axe-core/playwright');
const { seedOffering, destroyOffering, staffPage } = require('./helpers/seed');
const fixture = require('../tests/fixtures/license_bck2_golden.json');

const hasStaff = !!(process.env.E2E_USER && (process.env.E2E_PASSWORD || process.env.E2E_PASS));

test.describe('SDK embed', () => {
	test.skip(!hasStaff, 'E2E_USER/E2E_PASSWORD unset — seeding needs staff');

	let seeded;
	let priorLicense = '';
	let proActive = false;

	async function settingsToken(page) {
		// same token source as helpers/seed.js — the app pages carry
		// data-requesttoken in <head>; /settings itself is not a page route
		await page.goto('/apps/bookingcheck/offerings', { waitUntil: 'domcontentloaded' });
		return page.evaluate(() => document.head?.dataset?.requesttoken ?? '');
	}

	test.beforeAll(async ({ browser }) => {
		const page = await staffPage(browser);
		seeded = await seedOffering(page, { title: 'E2E SDK Yoga', priceCents: 0 });

		const base = new URL(page.url()).origin;
		const token = await settingsToken(page);
		const headers = { requesttoken: token, 'OCS-APIRequest': 'true', Accept: 'application/json' };

		const before = await page.request.get(`${base}/apps/bookingcheck/api/settings/general`, { headers });
		if (before.ok()) {
			priorLicense = (await before.json()).data?.settings?.license_key ?? '';
		}
		const put = await page.request.put(`${base}/apps/bookingcheck/api/settings/general`, {
			headers,
			form: { license_key: fixture.wireKey },
		});
		if (put.ok()) {
			proActive = (await put.json()).data?.license?.state === 'pro';
		}
		await page.context().close();
	});

	test.afterAll(async ({ browser }) => {
		const page = await staffPage(browser);
		// restore the prior license state — do not leak lab Pro into
		// suites that assert free-tier gating
		if (priorLicense !== undefined) {
			const base = new URL(page.url()).origin;
			const token = await settingsToken(page);
			await page.request.put(`${base}/apps/bookingcheck/api/settings/general`, {
				headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
				form: { license_key: priorLicense },
			});
		}
		if (seeded) { await destroyOffering(page, seeded.id); }
		await page.context().close();
	});

	async function mountWidget(page) {
		// same-origin page → fetch needs no CORS; embed_domains is only
		// exercised by truly cross-origin deployments
		await page.goto('/apps/bookingcheck/pub', { waitUntil: 'domcontentloaded' });
		const base = new URL(page.url()).origin;
		await page.setContent(
			`<bck-booking slug="${seeded.slug}" base="${base}/apps/bookingcheck" lang="en"></bck-booking>`,
		);
		await page.addScriptTag({ url: `${base}/apps/bookingcheck/pub/sdk.js` });
	}

	test('guest books a free event through the WebComponent', async ({ browser }) => {
		test.skip(!proActive, 'lab BCK2 license not honored (BCK_ALLOW_VENDOR_KEY_OVERRIDE off)');
		const ctx = await browser.newContext({ storageState: undefined });
		const page = await ctx.newPage();
		await mountWidget(page);

		const widget = page.locator('bck-booking');
		await expect(widget.locator('button')).toBeVisible({ timeout: 15_000 });
		await expect(widget.locator('h2')).toHaveText('E2E SDK Yoga');

		await widget.locator('input[type="text"][autocomplete="name"]').fill('SDK Buyer');
		await widget.locator('input[type="email"]').fill('sdk-buyer@example.test');
		const tos = widget.locator('input[type="checkbox"]');
		if (await tos.count()) { await tos.first().check(); }
		await widget.locator('button').click();

		// free order → action done → confirmation + manage link
		await expect(widget.locator('.ok')).toBeVisible({ timeout: 15_000 });
		await expect(widget.locator('.ok a')).toHaveAttribute('href', /\/pub\/m\//);
		await expect(widget.locator('button')).toBeDisabled();
		await ctx.close();
	});

	test('widget is WCAG-clean (axe) and shows unavailable state on bad slug', async ({ browser }) => {
		test.skip(!proActive, 'lab BCK2 license not honored');
		const ctx = await browser.newContext({ storageState: undefined });
		const page = await ctx.newPage();
		await mountWidget(page);

		const widget = page.locator('bck-booking');
		await expect(widget.locator('button')).toBeVisible({ timeout: 15_000 });
		const axe = await new AxeBuilder({ page })
			.include('bck-booking')
			.analyze();
		expect(axe.violations.filter(v => v.impact === 'critical' || v.impact === 'serious')).toEqual([]);

		// bad slug → graceful unavailable, not a crash
		// NOTE: page.url() may now be null after setContent on some drivers —
		// rebuild from the configured baseURL instead
		const base = test.info().project.use.baseURL || 'http://127.0.0.1:8081';
		await page.setContent('<bck-booking slug="no-such-offering" base="' + base + '/apps/bookingcheck" lang="en"></bck-booking>');
		await expect(page.locator('bck-booking').locator('p')).toBeVisible({ timeout: 15_000 });
		await ctx.close();
	});
});

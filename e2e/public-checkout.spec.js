// Guest purchase path — the money surface. A staff session seeds a
// published offering via the real REST API; an UNAUTHENTICATED browser
// then walks the public checkout exactly like a buyer.
const { test, expect } = require('@playwright/test');
const { seedOffering, destroyOffering, staffPage } = require('./helpers/seed');

const hasStaff = !!(process.env.E2E_USER && (process.env.E2E_PASSWORD || process.env.E2E_PASS));

test.describe('public checkout', () => {
	test.skip(!hasStaff, 'E2E_USER/E2E_PASSWORD unset — seeding needs staff');

	let seeded;
	test.beforeAll(async ({ browser }) => {
		const page = await staffPage(browser);
		seeded = await seedOffering(page, { title: 'E2E Smoke Concert', priceCents: 0 });
		await page.context().close();
	});
	test.afterAll(async ({ browser }) => {
		if (!seeded) { return; }
		const page = await staffPage(browser);
		await destroyOffering(page, seeded.id);
		await page.context().close();
	});

	test('guest buys a free ticket end to end', async ({ browser }) => {
		// a guest context: no staff cookies at all
		const ctx = await browser.newContext({ storageState: undefined });
		const page = await ctx.newPage();

		await page.goto(`/apps/bookingcheck/pub/o/${seeded.slug}/checkout`);
		await expect(page.locator('#bck-name')).toBeVisible();

		// occurrence is preselected when exactly one is available
		await expect(page.locator('input[name="occ"]').first()).toBeChecked();

		await page.fill('#bck-name', 'E2E Buyer');
		await page.fill('#bck-email', 'e2e-buyer@example.test');
		const consent = page.locator('input[name="consent"]');
		if (await consent.count()) {
			await consent.check();
		}
		await page.locator('#bck-pay-btn').click();

		// free order → action 'done' → JS navigates to the manage link
		await page.waitForURL(/\/pub\/m\//, { timeout: 30_000 });
		await expect(page.locator('main, #content')).toContainText(/BCK-/);

		// the order survives reload (durable, not session-only)
		await page.reload();
		await expect(page.locator('main, #content')).toContainText(/BCK-/);
		await ctx.close();
	});

	test('cancel booking asks before it destroys', async ({ browser }) => {
		const ctx = await browser.newContext({ storageState: undefined });
		const page = await ctx.newPage();
		await page.goto(`/apps/bookingcheck/pub/o/${seeded.slug}/checkout`);
		await page.fill('#bck-name', 'E2E Canceller');
		await page.fill('#bck-email', 'e2e-cancel@example.test');
		const consent = page.locator('input[name="consent"]');
		if (await consent.count()) { await consent.check(); }
		await page.locator('#bck-pay-btn').click();
		await page.waitForURL(/\/pub\/m\//, { timeout: 30_000 });

		// one stray tap must NOT cancel — the dialog owns the destructive act
		const cancelBtn = page.locator('form[data-bck-cancel-confirm] button[type=submit]');
		await expect(cancelBtn).toBeVisible();
		await cancelBtn.click();
		const dlg = page.locator('dialog[open]');
		await expect(dlg).toBeVisible();
		// dismiss → booking survives untouched
		await dlg.locator('.bck-dialog__actions .bck-btn--secondary').click();
		await expect(dlg).toBeHidden();
		await expect(cancelBtn).toBeVisible();
		// confirm → the booking is really cancelled
		await cancelBtn.click();
		await dlg.locator('.bck-dialog__actions button[type=submit]').click();
		await page.waitForLoadState('domcontentloaded');
		await expect(page.locator('main, #content')).toContainText(/cancelled/i);
		await ctx.close();
	});

	test('checkout is usable at 320px', async ({ browser }) => {
		const ctx = await browser.newContext({
			storageState: undefined,
			viewport: { width: 320, height: 640 },
		});
		const page = await ctx.newPage();
		await page.goto(`/apps/bookingcheck/pub/o/${seeded.slug}/checkout`);
		await expect(page.locator('#bck-name')).toBeVisible();
		await expect(page.locator('#bck-pay-btn')).toBeVisible();
		// no horizontal scroll trap
		const scrollW = await page.evaluate(() => document.documentElement.scrollWidth);
		expect(scrollW).toBeLessThanOrEqual(321);
		await ctx.close();
	});
});

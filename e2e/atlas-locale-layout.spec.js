// Lens 5 live proof — real translated text at real widths, not lorem
// ipsum. German is the longest shipped catalog; if a layout survives
// de-DE at 320/375px it survives every shipped locale.
const { test, expect } = require('@playwright/test');
const { seedOffering, destroyOffering, staffPage } = require('./helpers/seed');

const hasStaff = !!(process.env.E2E_USER && (process.env.E2E_PASSWORD || process.env.E2E_PASS));

test.describe('long-locale layout (de-DE)', () => {
	test.skip(!hasStaff, 'E2E_USER/E2E_PASSWORD unset — seeding needs staff');

	let seeded;
	test.beforeAll(async ({ browser }) => {
		const page = await staffPage(browser);
		seeded = await seedOffering(page, { title: 'Längste deutsche Bezeichnung mit sehr viel Text', priceCents: 0 });
		await page.context().close();
	});
	test.afterAll(async ({ browser }) => {
		if (!seeded) { return; }
		const page = await staffPage(browser);
		await destroyOffering(page, seeded.id);
		await page.context().close();
	});

	async function noHorizontalOverflow(page, maxWidth) {
		const scrollW = await page.evaluate(() => document.documentElement.scrollWidth);
		expect(scrollW).toBeLessThanOrEqual(maxWidth + 1);
	}

	test('public offering page renders German without overflow @375', async ({ browser }) => {
		const ctx = await browser.newContext({
			storageState: undefined,
			locale: 'de-DE',
			viewport: { width: 375, height: 720 },
		});
		const page = await ctx.newPage();
		await page.goto(`/apps/bookingcheck/pub/o/${seeded.slug}`);
		// Proof the locale actually applied: a German UI string renders
		// (not English fallback, not a raw i18n key).
		await expect(page.locator('body')).toContainText(/Jetzt|Plätze|Buchen|Termin|Freie/i);
		await expect(page.locator('body')).not.toContainText(/Book now|Available seats/i);
		await noHorizontalOverflow(page, 375);
		await ctx.close();
	});

	test('public checkout renders German without overflow @320', async ({ browser }) => {
		const ctx = await browser.newContext({
			storageState: undefined,
			locale: 'de-DE',
			viewport: { width: 320, height: 640 },
		});
		const page = await ctx.newPage();
		await page.goto(`/apps/bookingcheck/pub/o/${seeded.slug}/checkout`);
		await expect(page.locator('#bck-name')).toBeVisible();
		await noHorizontalOverflow(page, 320);
		await ctx.close();
	});
});

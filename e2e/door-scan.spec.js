// Door surface — anonymous tablets reach /scan and see only the token
// entry. Nothing about the staff area or guest data leaks pre-auth.
const { test, expect } = require('@playwright/test');

test.describe('door scanner entry', () => {
	test('anonymous /scan renders the token form', async ({ browser }) => {
		const ctx = await browser.newContext({ storageState: undefined });
		const page = await ctx.newPage();
		await page.goto('/apps/bookingcheck/scan', { waitUntil: 'domcontentloaded' });
		const tokenField = page.locator(
			'input[type="password"], input[name="token"], input[name="device_token"]',
		).first();
		await expect(tokenField).toBeVisible();
		await ctx.close();
	});

	test('staff API rejects anonymous callers', async ({ browser }) => {
		const ctx = await browser.newContext({ storageState: undefined });
		const res = await ctx.request.get('/apps/bookingcheck/api/offerings/1');
		expect([401, 403]).toContain(res.status());
		await ctx.close();
	});
});

// Offering lifecycle — the admin UI must reach the transition/duplicate
// endpoints (seed: offerings-cannot-be-published). Proves the click chain:
// draft → published → archived → draft, plus duplicate → new draft copy.
// Selectors are structural (ids/data-attrs) — locale-safe per §3.5.13.
const { test, expect } = require('@playwright/test');

const hasStaff = !!(process.env.E2E_USER && (process.env.E2E_PASSWORD || process.env.E2E_PASS));

test.describe('offering lifecycle', () => {
	test.skip(!hasStaff, 'E2E_USER/E2E_PASSWORD unset');

	let requestToken;
	const apiHeaders = () => ({ requesttoken: requestToken, 'OCS-APIRequest': 'true' });

	test.beforeEach(async ({ page }) => {
		await page.goto('/apps/bookingcheck/offerings', { waitUntil: 'domcontentloaded' });
		requestToken = await page.evaluate(() => window.OC && window.OC.requestToken);
		expect(requestToken).toBeTruthy();
	});

	test('publish → archive → restore → duplicate chain works end to end', async ({ page, request }) => {
		// unique fixture per run — parallel workers share this instance
		const title = 'E2E Lifecycle ' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
		const created = await request.post('/index.php/apps/bookingcheck/api/offerings', {
			headers: apiHeaders(),
			data: { title, type: 'event', price_cents: '500', currency: 'EUR' },
		});
		const createdBody = await created.json();
		const oid = createdBody && createdBody.data && createdBody.data.id;
		expect(oid).toBeTruthy();
		let copyId = null;

		try {
			// detail page — draft shows Publish, hides the rest
			await page.goto('/apps/bookingcheck/offerings/' + oid, { waitUntil: 'domcontentloaded' });
			await expect(page.locator('#bck-offering-summary-body h2')).toBeVisible();
			await expect(page.locator('#bck-offer-publish')).toBeVisible();
			await expect(page.locator('#bck-offer-archive')).toBeHidden();
			await expect(page.locator('#bck-offer-restore')).toBeHidden();

			// draft → published
			await page.locator('#bck-offer-publish').click();
			await expect(page.locator('#bck-offer-unpublish')).toBeVisible();
			await expect(page.locator('#bck-offer-archive')).toBeVisible();
			await expect(page.locator('#bck-offer-publish')).toBeHidden();

			// persisted? re-read via the data endpoint, not just the DOM
			const after1 = await request.get('/index.php/apps/bookingcheck/api/offerings/' + oid, { headers: apiHeaders() });
			expect((await after1.json()).data.offering.status).toBe('published');

			// published → archived — destructive direction needs the confirm dialog
			await page.locator('#bck-offer-archive').click();
			const dlg = page.locator('dialog.bck-dialog');
			await expect(dlg).toBeVisible();
			await dlg.locator('button[type="submit"]').click();
			await expect(page.locator('#bck-offer-restore')).toBeVisible();
			await expect(page.locator('#bck-offer-unpublish')).toBeHidden();

			const after2 = await request.get('/index.php/apps/bookingcheck/api/offerings/' + oid, { headers: apiHeaders() });
			expect((await after2.json()).data.offering.status).toBe('archived');

			// archived → draft (restore, no confirm — lossless direction)
			await page.locator('#bck-offer-restore').click();
			await expect(page.locator('#bck-offer-publish')).toBeVisible();

			const after3 = await request.get('/index.php/apps/bookingcheck/api/offerings/' + oid, { headers: apiHeaders() });
			expect((await after3.json()).data.offering.status).toBe('draft');

			// duplicate → navigates to the new draft copy — the wait must
			// exclude the current URL or it resolves before the click lands
			await Promise.all([
				page.waitForURL((u) => /\/offerings\/\d+$/.test(u.pathname)
					&& !u.pathname.endsWith('/offerings/' + oid)),
				page.locator('#bck-offer-duplicate').click(),
			]);
			copyId = parseInt(page.url().match(/\/offerings\/(\d+)$/)[1], 10);
			expect(copyId).not.toBe(oid);
			await expect(page.locator('#bck-offering-summary-body h2')).toBeVisible();
			const copyData = await request.get('/index.php/apps/bookingcheck/api/offerings/' + copyId, { headers: apiHeaders() });
			const copy = (await copyData.json()).data.offering;
			expect(copy.status).toBe('draft');
			expect(copy.title).toBe(title + ' (copy)');

			// a draft card on the list exposes a Publish button directly
			await page.goto('/apps/bookingcheck/offerings', { waitUntil: 'domcontentloaded' });
			await expect(
				page.locator('[data-bck-offer-transition="' + copyId + '"][data-bck-to="published"]'),
			).toBeVisible();
		} finally {
			for (const id of [copyId, oid]) {
				if (id) {
					await request.delete('/index.php/apps/bookingcheck/api/offerings/' + id, { headers: apiHeaders() });
				}
			}
		}
	});
});

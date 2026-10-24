// Family-wide form-rhythm sweep — walks every major BookingCheck surface and
// asserts the invariant that regressed 2026-10-24: inside a vertically
// stacked .bck-field, the label must sit CLOSER to its own control than the
// distance to the next field. Also captures full-page screenshots per surface
// so visual regressions are reviewable.
const { test, expect } = require('@playwright/test');
const { staffPage, staffStorageState, seedOffering, destroyOffering } = require('./helpers/seed');

const hasStaff = !!(process.env.E2E_USER && process.env.E2E_PASSWORD);
const OUT = process.env.BCK_SWEEP_OUT || '/tmp/bck-rhythm';

// Returns, per form, violations of the rhythm contract. A field is "stacked"
// when the next field starts at or below this field's bottom edge.
async function auditRhythm(page) {
	return page.evaluate(() => {
		const out = [];
		const forms = [...document.querySelectorAll('form')];
		for (const form of forms) {
			const fields = [...form.querySelectorAll('.bck-field:not([hidden])')]
				.filter((f) => f.getClientRects().length);
			for (let i = 0; i < fields.length; i++) {
				const f = fields[i];
				const label = f.querySelector(':scope > label, :scope > .bck-label, :scope > legend');
				const control = f.querySelector('input, select, textarea');
				if (!label || !control) { continue; }
				const l = label.getBoundingClientRect();
				// label-wrapped controls (checkbox rows, radio pills): measure the
				// visual wrapper edge, not the inner input's padded rect
				const wrap = control.closest('label');
				const wrapped = wrap === label; // row IS the control (checkbox/radio row)
				const c = (wrap && f.contains(wrap)) ? wrap.getBoundingClientRect() : control.getBoundingClientRect();
				const labelGap = wrapped ? 0 : Math.round(c.top - l.bottom);
				// find the next field stacked below this one
				const next = fields[i + 1];
				let fieldGap = null;
				if (next) {
					const fr = f.getBoundingClientRect();
					const nr = next.getBoundingClientRect();
					if (nr.top >= fr.bottom - 1 && Math.abs(nr.left - fr.left) < 40) {
						fieldGap = Math.round(nr.top - fr.bottom);
					}
				}
				if (labelGap < 0 || labelGap > 16) {
					out.push(`label→control ${labelGap}px (want 0..16) — "${(label.textContent || '').trim().slice(0, 40)}"`);
				}
				if (fieldGap !== null && fieldGap < 4) {
					out.push(`field→field ${fieldGap}px — stacked siblings crushed ("${(label.textContent || '').trim().slice(0, 40)}")`);
				}
				if (fieldGap !== null && labelGap > fieldGap) {
					out.push(`inverted: label→control ${labelGap}px > field→field ${fieldGap}px`);
				}
			}
		}
		return out;
	});
}

async function shoot(page, name) {
	const fs = require('fs');
	fs.mkdirSync(OUT, { recursive: true });
	await page.screenshot({ path: `${OUT}/${name}.png`, fullPage: true });
}

test.describe('form rhythm sweep — all surfaces', () => {
	test.skip(!hasStaff, 'E2E_USER/E2E_PASSWORD unset — needs staff session');

	let seeded;
	test.beforeAll(async ({ browser }) => {
		const page = await staffPage(browser);
		seeded = await seedOffering(page, { title: 'Rhythm Sweep ' + Date.now().toString(36), priceCents: 500 });
		await page.context().close();
	});
	test.afterAll(async ({ browser }) => {
		if (!seeded) { return; }
		const page = await staffPage(browser);
		await destroyOffering(page, seeded.id);
		await page.context().close();
	});

	const adminPages = [
		['overview', '/apps/bookingcheck/overview'],
		['offerings', '/apps/bookingcheck/offerings'],
		['offering-new', '/apps/bookingcheck/offerings/new'],
		['orders', '/apps/bookingcheck/orders'],
		['reports', '/apps/bookingcheck/reports'],
		['reviews', '/apps/bookingcheck/reviews'],
		['promos', '/apps/bookingcheck/promos'],
		['vouchers', '/apps/bookingcheck/vouchers'],
		['resources', '/apps/bookingcheck/resources'],
		['roster', '/apps/bookingcheck/roster'],
		['waitlist', '/apps/bookingcheck/waitlist'],
		['webhooks', '/apps/bookingcheck/webhooks'],
		['audit', '/apps/bookingcheck/audit'],
		['settings', '/apps/bookingcheck/settings'],
		['settings-access', '/apps/bookingcheck/settings/access'],
		['settings-flow', '/apps/bookingcheck/settings/flow'],
		['settings-payments', '/apps/bookingcheck/settings/payments'],
		['settings-invoicing', '/apps/bookingcheck/settings/invoicing'],
		['settings-integrations', '/apps/bookingcheck/settings/integrations'],
	];

	for (const [name, url] of adminPages) {
		test(`admin: ${name}`, async ({ browser }) => {
			const page = await staffPage(browser);
			await page.goto(url, { waitUntil: 'domcontentloaded' });
			await page.waitForTimeout(600);
			const violations = await auditRhythm(page);
			await shoot(page, `admin-${name}`);
			await page.context().close();
			expect(violations, `${url} rhythm violations:\n${violations.join('\n')}`).toEqual([]);
		});
	}

	test('admin: offering detail + edit', async ({ browser }) => {
		const page = await staffPage(browser);
		await page.goto(`/apps/bookingcheck/offerings/${seeded.id}`, { waitUntil: 'domcontentloaded' });
		await page.waitForTimeout(800);
		const v1 = await auditRhythm(page);
		await shoot(page, 'admin-offering-detail');
		await page.goto(`/apps/bookingcheck/offerings/${seeded.id}/edit`, { waitUntil: 'domcontentloaded' });
		await page.waitForTimeout(400);
		const v2 = await auditRhythm(page);
		await shoot(page, 'admin-offering-edit');
		await page.context().close();
		expect([...v1, ...v2]).toEqual([]);
	});

	test('public: catalog + offering + checkout', async ({ browser }) => {
		const ctx = await browser.newContext({ storageState: undefined });
		const page = await ctx.newPage();
		for (const [name, url] of [
			['pub-catalog', '/apps/bookingcheck/pub'],
			['pub-offering', `/apps/bookingcheck/pub/o/${seeded.slug}`],
			['pub-checkout', `/apps/bookingcheck/pub/o/${seeded.slug}/checkout`],
		]) {
			await page.goto(url, { waitUntil: 'domcontentloaded' });
			await page.waitForTimeout(600);
			const violations = await auditRhythm(page);
			await shoot(page, name);
			expect(violations, `${url} rhythm violations:\n${violations.join('\n')}`).toEqual([]);
		}
		await ctx.close();
	});

	test('public: manage page (answers + upload + review forms)', async ({ browser }) => {
		// a free order redirects straight to /pub/m/{token} — the manage
		// surface hosts the intake answers, upload and review forms
		const staff = await staffPage(browser);
		const free = await seedOffering(staff, { title: 'Rhythm Free ' + Date.now().toString(36), priceCents: 0 });
		await staff.context().close();
		const ctx = await browser.newContext({ storageState: undefined });
		const page = await ctx.newPage();
		await page.goto(`/apps/bookingcheck/pub/o/${free.slug}/checkout`, { waitUntil: 'domcontentloaded' });
		await page.fill('#bck-name', 'Rhythm Buyer');
		await page.fill('#bck-email', 'rhythm@example.test');
		const consent = page.locator('input[name="consent"]');
		if (await consent.count()) { await consent.check(); }
		await page.locator('#bck-pay-btn').click();
		await page.waitForURL(/\/pub\/m\//, { timeout: 30_000 });
		await page.waitForTimeout(800);
		const violations = await auditRhythm(page);
		await shoot(page, 'pub-manage');
		await ctx.close();
		const cleanup = await staffPage(browser);
		await destroyOffering(cleanup, free.id);
		await cleanup.context().close();
		expect(violations).toEqual([]);
	});
});

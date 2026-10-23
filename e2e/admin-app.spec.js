// Staff surface — the app must load, list offerings, and stay
// keyboard/AT-usable (axe WCAG 2.1 AA gate).
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;

const hasStaff = !!(process.env.E2E_USER && (process.env.E2E_PASSWORD || process.env.E2E_PASS));

test.describe('staff app', () => {
	test.skip(!hasStaff, 'E2E_USER/E2E_PASSWORD unset');

	test('offerings index renders and passes axe', async ({ page }) => {
		await page.goto('/apps/bookingcheck/offerings', { waitUntil: 'domcontentloaded' });
		await expect(page.locator('#app-content, main').first()).toBeVisible();

		const results = await new AxeBuilder({ page })
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze();
		const serious = results.violations.filter(
			(v) => v.impact === 'critical' || v.impact === 'serious',
		);
		expect(serious, JSON.stringify(serious.map((v) => v.id))).toEqual([]);
	});

	test('create form renders with labelled fields', async ({ page }) => {
		await page.goto('/apps/bookingcheck/offerings/new', { waitUntil: 'domcontentloaded' });
		await expect(page.locator('[name="title"], #bck-title').first()).toBeVisible();
		const unlabelled = await page.$$eval(
			'input:not([type="hidden"]):not([type="submit"]), select, textarea',
			(els) => els.filter((el) => {
				const id = el.id;
				const labelled = el.getAttribute('aria-label')
					|| el.getAttribute('aria-labelledby')
					|| (id && document.querySelector(`label[for="${id}"]`))
					|| el.closest('label');
				return !labelled;
			}).map((el) => el.name || el.id),
		);
		expect(unlabelled).toEqual([]);
	});

	test('create form hides fields that do not apply to the picked type', async ({ page }) => {
		await page.goto('/apps/bookingcheck/offerings/new', { waitUntil: 'domcontentloaded' });
		const type = page.locator('#bck-type');
		const seriesWrap = page.locator('#bck-bookseries-wrap');
		// default 'event' — series booking is a course-only option
		await expect(seriesWrap).toBeHidden();
		await type.selectOption('course');
		await expect(seriesWrap).toBeVisible();
		// switching away hides AND clears it — no invisible state can be saved
		await page.locator('input[type="checkbox"][name="book_series"]').check();
		await type.selectOption('event');
		await expect(seriesWrap).toBeHidden();
		await expect(page.locator('input[type="checkbox"][name="book_series"]')).not.toBeChecked();
		// Pro-only conditional fields stay out of the way until relevant
		const passScope = page.locator('#bck-passscope-wrap');
		if (await passScope.count()) {
			await expect(passScope).toBeHidden();
			if (await type.locator('option[value="pass"]').count()) {
				await type.selectOption('pass');
				await expect(passScope).toBeVisible();
			}
		}
		const dynStep = page.locator('#bck-dynstep-field');
		if (await dynStep.count()) {
			await expect(dynStep).toBeHidden();
			await page.locator('#bck-dynbp').fill('10');
			await expect(dynStep).toBeVisible();
			await page.locator('#bck-dynbp').fill('0');
			await expect(dynStep).toBeHidden();
		}
		const shareScope = page.locator('#bck-shscope-field');
		if (await shareScope.count()) {
			const shareChk = page.locator('input[type="checkbox"][name="sharing_enabled"]');
			await expect(shareScope).toBeHidden();
			await shareChk.check();
			await expect(shareScope).toBeVisible();
			await expect(page.locator('#bck-uploadquota-wrap')).toBeVisible();
		}
	});

	test('card tables keep column labels at phone width', async ({ page }) => {
		// bck-table--cards collapses to card rows <769px; each td must carry
		// data-cell or the row renders as unlabeled values (drift regression).
		await page.setViewportSize({ width: 375, height: 800 });
		await page.goto('/apps/bookingcheck/orders', { waitUntil: 'domcontentloaded' });
		await expect(page.locator('#app-content, main').first()).toBeVisible();

		const unlabeled = await page.$$eval(
			'.bck-table--cards tbody td',
			(els) => els.filter((el) => !el.hasAttribute('data-cell') && !el.hasAttribute('colspan')).length,
		);
		expect(unlabeled).toBe(0);

		// The label must actually render via ::before — an attr alone proves nothing.
		const firstCell = page.locator('.bck-table--cards tbody td[data-cell]').first();
		if (await firstCell.count()) {
			const label = await firstCell.evaluate((el) => getComputedStyle(el, '::before').content);
			expect(label.length).toBeGreaterThan(2); // '"Label"' incl. quotes
		}

		// No horizontal overflow on the page.
		const scrollW = await page.evaluate(() => document.documentElement.scrollWidth);
		expect(scrollW).toBeLessThanOrEqual(376);
	});

	test('JS-built tables emit card rows — roster action cell reachable at 320px', async ({ page }) => {
		// Regression: the roster table is built in admin.js, not a template —
		// it shipped without bck-table--cards/data-cell and clipped the
		// check-in button into a horizontal scroll trap on phones.
		const { seedOffering, destroyOffering } = require('./helpers/seed.js');
		await page.goto('/apps/bookingcheck/offerings', { waitUntil: 'domcontentloaded' });
		const seeded = await seedOffering(page, { title: `Roster cards ${Date.now()}`, capacity: 3 });

		// A guest order puts a real row on the roster.
		const order = await page.request.post('/apps/bookingcheck/pub/api/orders', {
			form: {
				offering_id: String(seeded.id), buyer_name: 'Card Roster', buyer_email: `bck-cards-${Date.now()}@example.test`,
				locale: 'en', consent: '1', idem_token: `cd${Date.now().toString(36)}x${Math.random().toString(36).slice(2, 12)}`,
				'lines[0][target_type]': 'event', 'lines[0][target_id]': String(seeded.eventId), 'lines[0][seats]': '1',
			},
		});
		expect(order.ok(), `guest order: ${order.status()}`).toBeTruthy();

		try {
			await page.setViewportSize({ width: 320, height: 800 });
			await page.goto('/apps/bookingcheck/roster', { waitUntil: 'domcontentloaded' });
			const picker = page.locator('[data-bck-roster-picker]');
			await expect(picker).toBeVisible();
			await picker.selectOption(`event:${seeded.eventId}`);
			const table = page.locator('#bck-roster-list table.bck-table');
			await expect(table).toBeVisible();
			await expect(table).toHaveClass(/bck-table--cards/);

			// Every labelled column carries data-cell; the action cell does not.
			const unlabeled = await page.$$eval(
				'#bck-roster-list .bck-table--cards tbody td',
				(els) => els.filter((el) => !el.hasAttribute('data-cell')
					&& !el.hasAttribute('colspan')
					&& !el.classList.contains('bck-table-actions-col')).length,
			);
			expect(unlabeled).toBe(0);

			// The check-in action renders INSIDE the 320px card row — never
			// clipped off-screen behind the scroll wrap.
			const action = page.locator('#bck-roster-list [data-bck-checkin]').first();
			await expect(action).toBeVisible();
			const box = await action.boundingBox();
			expect(box).not.toBeNull();
			expect(box.x).toBeGreaterThanOrEqual(0);
			expect(box.x + box.width).toBeLessThanOrEqual(321);
		} finally {
			await destroyOffering(page, seeded.id);
		}
	});

	test('page title wraps long compounds instead of clipping at 320px', async ({ page }) => {
		// Regression: the settings H1 clipped "…steuerun|g" at the viewport
		// edge — minmax(0,1fr) alone does not break unbreakable words.
		await page.setViewportSize({ width: 320, height: 800 });
		await page.goto('/apps/bookingcheck/settings/access', { waitUntil: 'domcontentloaded' });
		const h1 = page.locator('.bck-page-header__text h1, .bck-page-title').first();
		await expect(h1).toBeVisible();
		// Locale-independent: synthesize the failing shape — a long German
		// compound after the section separator — and measure the overflow.
		const overflow = await h1.evaluate((el) => {
			const orig = el.textContent;
			el.textContent = `Einstellungen — Zugriffssteuerungsorganisationshilfe`;
			const clipped = el.scrollWidth - el.clientWidth;
			el.textContent = orig;
			return clipped;
		});
		expect(overflow).toBeLessThanOrEqual(1);
		const docOverflow = await page.evaluate(() => document.documentElement.scrollWidth);
		expect(docOverflow).toBeLessThanOrEqual(321);
	});

	test('button variants keep declared chrome on <button> elements', async ({ page }) => {
		// Regression: NC core `button:not(.button-vue)` (0-1-1) repainted every
		// variant to pale primary-element-light + border:none — single-class
		// rules (0-1-0) lost. Variants are now ID-scoped + !important, incl.
		// #content.app-bookingcheck so guest/public surfaces are covered too.
		await page.goto('/apps/bookingcheck/settings/access', { waitUntil: 'domcontentloaded' });
		await expect(page.locator('#app-content, main').first()).toBeVisible();

		const measured = await page.evaluate(() => {
			const out = {};
			const tokenProbe = document.createElement('div');
			tokenProbe.style.backgroundColor = 'var(--color-primary-element)';
			(document.body).appendChild(tokenProbe);
			out.primaryToken = getComputedStyle(tokenProbe).backgroundColor;
			out.paleLight = (() => {
				const p = document.createElement('div');
				p.style.backgroundColor = 'var(--color-primary-element-light)';
				document.body.appendChild(p);
				const v = getComputedStyle(p).backgroundColor;
				p.remove();
				return v;
			})();
			tokenProbe.remove();

			// Probe once inside the staff shell and once under bare #content —
			// the second shape is what public/guest pages look like (no
			// #app-content wrapper in layout.public.php).
			const scopes = {
				staff: document.querySelector('#app-content .bck-page-stack')
					|| document.querySelector('#app-content') || document.body,
				pub: document.getElementById('content') || document.body,
			};
			for (const [scope, host] of Object.entries(scopes)) {
				const wrap = document.createElement('div');
				wrap.className = 'bck-pub';
				host.appendChild(wrap);
				for (const v of ['primary', 'secondary', 'ghost', 'flat', 'danger']) {
					const btn = document.createElement('button');
					btn.className = `bck-btn bck-btn--${v}`;
					const anchor = document.createElement('a');
					anchor.className = `bck-btn bck-btn--${v}`;
					wrap.appendChild(btn); wrap.appendChild(anchor);
					const cb = getComputedStyle(btn);
					const ca = getComputedStyle(anchor);
					out[`${scope}.${v}`] = {
						btnBg: cb.backgroundColor, btnBorderStyle: cb.borderTopStyle,
						btnBorderColor: cb.borderTopColor, anchorBg: ca.backgroundColor,
						anchorBorderStyle: ca.borderTopStyle,
					};
					btn.remove(); anchor.remove();
				}
				wrap.remove();
			}
			return out;
		});

		for (const scope of ['staff', 'pub']) {
			const p = measured[`${scope}.primary`];
			expect(p.btnBg, `${scope} primary bg should be the solid token, got ${p.btnBg}`).toBe(measured.primaryToken);
			expect(p.btnBg, `${scope} primary must not collapse to pale element-light`).not.toBe(measured.paleLight);
			expect(p.btnBorderStyle, `${scope} primary border must render`).toBe('solid');
			expect(p.btnBg).toBe(p.anchorBg); // <button> === <a> parity
			for (const v of ['secondary', 'ghost', 'flat', 'danger']) {
				const m = measured[`${scope}.${v}`];
				expect(m.btnBg, `${scope} ${v} collapsed to pale element-light`).not.toBe(measured.paleLight);
				expect(m.btnBg, `${scope} ${v} <button>/<a> parity`).toBe(m.anchorBg);
				expect(m.btnBorderStyle, `${scope} ${v} border must render (core border:none must lose)`).toBe('solid');
			}
		}
	});
});

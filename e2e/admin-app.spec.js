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
});

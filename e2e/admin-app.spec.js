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
});

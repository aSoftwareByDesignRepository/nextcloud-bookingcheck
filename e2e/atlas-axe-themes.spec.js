// Atlas Lens 3 — axe WCAG 2.1 AA per theme, not just light.
// NC user themes land as body[data-theme-*]; every custom token must
// resolve correctly under each. The public guest catalog is
// theme-invariant by design (guests have no user theme), so it is
// covered once, separately, in the same run.
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { setUserTheme, resetUserTheme, USER_THEMES } = require('./helpers/theming.js');

const hasStaff = !!(process.env.E2E_USER && (process.env.E2E_PASSWORD || process.env.E2E_PASS));

const STAFF_SURFACES = [
	{ name: 'offerings', url: '/apps/bookingcheck/offerings' },
	{ name: 'orders', url: '/apps/bookingcheck/orders' },
	{ name: 'offering-new', url: '/apps/bookingcheck/offerings/new' },
	{ name: 'settings-portal', url: '/apps/bookingcheck/settings/portal' },
];

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

async function axeClean(page, label) {
	const results = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
	const serious = results.violations.filter(
		(v) => v.impact === 'critical' || v.impact === 'serious',
	);
	expect(
		serious,
		`${label}: ${JSON.stringify(serious.map((v) => `${v.id}@${v.nodes.length}nodes`))}`,
	).toEqual([]);
}

test.describe('staff surfaces per theme (WCAG 2.1 AA)', () => {
	test.skip(!hasStaff, 'E2E_USER/E2E_PASSWORD unset');
	test.describe.configure({ mode: 'serial' }); // theme is per-user state; parallel workers would fight

	for (const theme of USER_THEMES) {
		for (const surface of STAFF_SURFACES) {
			test(`${surface.name} axe-clean under ${theme}`, async ({ page }) => {
				await page.goto('/apps/bookingcheck/offerings', { waitUntil: 'domcontentloaded' });
				await setUserTheme(page, theme);
				await page.goto(surface.url, { waitUntil: 'domcontentloaded' });
				await expect(page.locator('#app-content, main').first()).toBeVisible();
				await axeClean(page, `${surface.name}@${theme}`);
			});
		}
	}

	// Runs last under `serial` — restores the fixture user to no-theme so a
	// dark high-contrast pass cannot poison later suites or screenshots.
	test('fixture user theme restored', async ({ page }) => {
		await page.goto('/apps/bookingcheck/offerings', { waitUntil: 'domcontentloaded' });
		await resetUserTheme(page);
	});
});

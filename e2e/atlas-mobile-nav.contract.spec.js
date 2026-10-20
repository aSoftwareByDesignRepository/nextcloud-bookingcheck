// ATLAS_MOBILE_NAV_CONTRACT — the in-page menu must open/close at 375px:
// toggle wired, aria-expanded flips, outside-tap closes. Guards the
// arbeitszeitcheck#33 defect class (core #app-navigation-toggle dead under
// overflow:hidden).
const { test } = require('@playwright/test');
const { assertAtlasMobileNav } = require('../../_shared/e2e/atlas-mobile-nav-contract');

const BASE = (process.env.E2E_BASE || 'http://127.0.0.1:8081').replace(/\/$/, '');

test.describe('ATLAS_MOBILE_NAV_CONTRACT', () => {
	test.skip(
		!(process.env.E2E_USER && (process.env.E2E_PASSWORD || process.env.E2E_PASS)),
		'E2E_USER/E2E_PASSWORD unset',
	);

	test('mobile nav opens and closes on the offerings page', async ({ page }) => {
		await page.setViewportSize({ width: 375, height: 812 });
		await page.goto(`${BASE}/apps/bookingcheck/offerings`, { waitUntil: 'domcontentloaded' });
		await assertAtlasMobileNav(page, {
			toggle: page.locator('#bck-nav-toggle'),
			nav: page.locator('#app-navigation'),
			openClass: /bck-nav--open/,
		});
	});
});

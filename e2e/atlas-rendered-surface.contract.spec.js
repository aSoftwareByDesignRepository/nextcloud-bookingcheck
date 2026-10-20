// ATLAS_RENDERED_SURFACE_CONTRACT — asserts the *rendered* truth of each
// page surface: list markers not reset to none, selects vertically centred,
// no 0x0 dead icons, no centered form controls. DOM-level specs pass on
// pages that are visually broken.
const { test } = require('@playwright/test');
const { assertAtlasRenderedSurface } = require('../../_shared/e2e/atlas-rendered-surface-contract');

const BASE = (process.env.E2E_BASE || 'http://127.0.0.1:8081').replace(/\/$/, '');

const STAFF_SURFACES = [
	['/apps/bookingcheck/', 'app index'],
	['/apps/bookingcheck/overview', 'overview'],
	['/apps/bookingcheck/offerings', 'offerings list'],
	['/apps/bookingcheck/offerings/new', 'offering create form'],
	['/apps/bookingcheck/orders', 'orders list'],
	['/apps/bookingcheck/reports', 'reports'],
	['/apps/bookingcheck/audit', 'audit log'],
];

test.describe('Rendered-surface contract — staff', () => {
	test.skip(
		!(process.env.E2E_USER && (process.env.E2E_PASSWORD || process.env.E2E_PASS)),
		'E2E_USER/E2E_PASSWORD unset',
	);

	for (const [path, name] of STAFF_SURFACES) {
		test(`ATLAS_RENDERED_SURFACE_CONTRACT ${name} (${path})`, async ({ page }) => {
			await page.goto(`${BASE}${path}`, { waitUntil: 'domcontentloaded' });
			await assertAtlasRenderedSurface(page, {
				content: '#bck-main-content, #app-content',
				navExclude: '#app-navigation, nav, .bck-nav',
			});
		});
	}
});

const GUEST_SURFACES = [
	['/apps/bookingcheck/pub', 'public catalog'],
	['/apps/bookingcheck/scan', 'door token entry'],
];

test.describe('Rendered-surface contract — guest', () => {
	test.use({ storageState: { cookies: [], origins: [] } });

	for (const [path, name] of GUEST_SURFACES) {
		test(`ATLAS_RENDERED_SURFACE_CONTRACT guest ${name} (${path})`, async ({ page }) => {
			await page.goto(`${BASE}${path}`, { waitUntil: 'domcontentloaded' });
			await assertAtlasRenderedSurface(page, {
				content: '#bck-main-content, main, body',
				navExclude: '#app-navigation, nav, .bck-nav',
			});
		});
	}
});

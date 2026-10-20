// ATLAS_VERTICAL_SCROLL_CONTRACT — a page taller than the viewport must
// scroll to its last child. Guards CSS Overflow L3 unpaired overflow-x:clip
// truncating content.
const { test } = require('@playwright/test');
const { assertAtlasVerticalScrollReachable } = require('../../_shared/e2e/atlas-vertical-scroll-contract');

const BASE = (process.env.E2E_BASE || 'http://127.0.0.1:8081').replace(/\/$/, '');

test.describe('ATLAS_VERTICAL_SCROLL_CONTRACT', () => {
	test.skip(
		!(process.env.E2E_USER && (process.env.E2E_PASSWORD || process.env.E2E_PASS)),
		'E2E_USER/E2E_PASSWORD unset',
	);

	test('offering create form scrolls to its last control', async ({ page }) => {
		// short viewport so the long form overflows and must scroll
		await page.setViewportSize({ width: 1280, height: 560 });
		await page.goto(`${BASE}/apps/bookingcheck/offerings/new`, { waitUntil: 'domcontentloaded' });
		await assertAtlasVerticalScrollReachable(page, {
			scrollport: '#app-content',
			target: '#bck-main-content .bck-form > :last-child, #bck-main-content form > :last-child, #bck-main-content > :last-child',
		});
	});
});

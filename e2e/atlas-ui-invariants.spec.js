// ATLAS_UI_INVARIANTS — DOM-level a11y + i18n + console hygiene on the
// app's main surfaces. The rendered-surface spec covers the pixel class;
// this covers structure/labels/raw keys that DOM CAN see.
const { test } = require('@playwright/test');
const {
	assertA11yDom,
	assertNoConsoleErrors,
	assertNoRawI18nKeys,
	trackConsoleErrors,
} = require('../../_shared/e2e/atlas-ui-invariants');

const BASE = (process.env.E2E_BASE || 'http://127.0.0.1:8081').replace(/\/$/, '');
const CONTENT = '#bck-main-content, #app-content';

const STAFF_SURFACES = [
	['/apps/bookingcheck/', 'app index'],
	['/apps/bookingcheck/offerings', 'offerings list'],
	['/apps/bookingcheck/offerings/new', 'offering create form'],
	['/apps/bookingcheck/orders', 'orders list'],
];

test.describe('ATLAS_UI_INVARIANTS — staff', () => {
	test.skip(
		!(process.env.E2E_USER && (process.env.E2E_PASSWORD || process.env.E2E_PASS)),
		'E2E_USER/E2E_PASSWORD unset',
	);

	for (const [path, name] of STAFF_SURFACES) {
		test(`ATLAS_UI_INVARIANTS ${name}`, async ({ page }) => {
			const errs = trackConsoleErrors(page);
			await page.goto(`${BASE}${path}`, { waitUntil: 'domcontentloaded' });
			const findings = await assertA11yDom(page, {
				content: CONTENT,
				// .bck-checkbox/.bck-check labels are the real ≥44px target; the
				// ~20px input inside is a deliberate sub-target (sibling allow-list class).
				sizeAllow: '.bck-check input, .bck-checkbox input',
			});
			test.expect(findings).toEqual([]);
			assertNoConsoleErrors(errs, { allow: [/favicon/] });
			await assertNoRawI18nKeys(page, { content: CONTENT });
		});
	}
});

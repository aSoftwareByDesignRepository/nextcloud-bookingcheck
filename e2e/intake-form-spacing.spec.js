// Intake form layout contract — "Questions for attendees" on offering detail.
// Regression: sibling .bck-field blocks had 0px spacing while label→input
// inside each field was 12px (gap + stray label margin) — labels visually
// attached to the previous control, and the lone "required" flag rendered as
// a bordered chip pill (bck-check recipe is for chip groups, not flags).
const { test, expect } = require('@playwright/test');

test.describe('intake form layout contract', () => {
	let requestToken;
	const apiHeaders = () => ({ requesttoken: requestToken, 'OCS-APIRequest': 'true' });

	test.beforeEach(async ({ page }) => {
		await page.goto('/apps/bookingcheck/offerings', { waitUntil: 'domcontentloaded' });
		requestToken = await page.evaluate(() => window.OC && window.OC.requestToken);
		expect(requestToken).toBeTruthy();
	});

	test('fields group correctly: label hugs its own control, fields are separated', async ({ page, request }) => {
		const created = await request.post('/index.php/apps/bookingcheck/api/offerings', {
			headers: apiHeaders(),
			data: { title: 'E2E Spacing ' + Date.now().toString(36), type: 'event', price_cents: '500', currency: 'EUR' },
		});
		const oid = (await created.json()).data.id;
		await page.goto('/apps/bookingcheck/offerings/' + oid, { waitUntil: 'domcontentloaded' });
		const form = page.locator('#bck-intake-form');
		await expect(form).toBeVisible();

		const m = await page.evaluate(() => {
			const form = document.getElementById('bck-intake-form');
			const fields = [...form.querySelectorAll('.bck-field:not([hidden])')];
			const r = (el) => el.getBoundingClientRect();
			const label1 = fields[0].querySelector('label');
			const input1 = fields[0].querySelector('.bck-input, .bck-select');
			const label2 = fields[1].querySelector('label');
			return {
				formDisplay: getComputedStyle(form).display,
				formGap: getComputedStyle(form).gap,
				labelToInput: r(input1).top - r(label1).bottom,
				fieldToField: r(label2).top - r(input1).bottom,
			};
		});

		// Proximity contract (Gestalt): a label must sit closer to its own
		// control than to the previous field's control.
		expect(m.formDisplay).toBe('flex');
		expect(m.labelToInput, `label→input ${m.labelToInput}px must be < field→field ${m.fieldToField}px`)
			.toBeLessThan(m.fieldToField);
		expect(m.fieldToField).toBeGreaterThanOrEqual(8);

		// The required flag is a plain checkbox row — never the bordered chip.
		const flag = form.locator('label.bck-checkbox');
		await expect(flag).toBeVisible();
		await expect(flag).not.toHaveCSS('border-top-style', 'solid');
		await expect(flag).toContainText(/required|erforderlich/i);
	});
});

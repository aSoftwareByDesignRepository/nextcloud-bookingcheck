// Settings → License — rendered proof that closed pack modals never paint
// (closed_dialog_painted) and that overlay-mounted close buttons keep their
// armor against the Nextcloud core button repaint
// (overlay_toast_button_armor_gap). Static gates prove the rules exist;
// this spec proves the cascade on the live page.
const { test, expect } = require('@playwright/test');
const { staffPage } = require('./helpers/seed');

const hasStaff = !!(process.env.E2E_USER && (process.env.E2E_PASSWORD || process.env.E2E_PASS));
const PAGE = '/apps/bookingcheck/settings/license';

test.describe('license surfaces', () => {
	test.skip(!hasStaff, 'E2E_USER/E2E_PASSWORD unset');

	test('closed license modals compute display:none and never intercept', async ({ browser }) => {
		const page = await staffPage(browser);
		await page.goto(PAGE, { waitUntil: 'domcontentloaded' });
		await page.waitForSelector('#bck-settings-root, .bck-license-page, dialog.bck-license-modal', { timeout: 15000 });

		const modals = await page.$$eval('dialog.bck-license-modal', els => els.map(el => ({
			id: el.id,
			open: el.open,
			display: getComputedStyle(el).display,
			visibility: getComputedStyle(el).visibility,
		})));
		expect(modals.length).toBeGreaterThan(0);
		for (const m of modals) {
			expect(m.open, `${m.id} must not render open on load`).toBe(false);
			expect(m.display, `${m.id} closed <dialog> must compute display:none`).toBe('none');
		}

		// Closed modals must not sit in the hit-test path: the element under
		// the dialog's static position is a real page element, not the dialog.
		const intercepts = await page.$$eval('dialog.bck-license-modal', els =>
			els.some(el => {
				const r = el.getBoundingClientRect();
				if (r.width === 0 || r.height === 0) return false;
				const hit = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
				return hit === el || el.contains(hit);
			}));
		expect(intercepts, 'a closed license modal intercepts pointer events').toBe(false);
		await page.context().close();
	});

	test('overlay close buttons keep armor against the core button repaint', async ({ browser }) => {
		const page = await staffPage(browser);
		await page.goto(PAGE, { waitUntil: 'domcontentloaded' });
		const probe = await page.evaluate(() => {
			const toasts = document.createElement('div');
			toasts.id = 'bck-toasts';
			toasts.innerHTML = '<div class="bck-toast"><button type="button" class="bck-toast__close">x</button></div>';
			const dlg = document.createElement('dialog');
			dlg.className = 'bck-dialog';
			dlg.innerHTML = '<button type="button" class="bck-dialog__close">x</button>';
			document.body.append(toasts, dlg);
			const cs = (el, props) => Object.fromEntries(props.map(p => [p, getComputedStyle(el)[p]]));
			const props = ['width', 'background-color', 'background-image', 'border-top-width', 'border-top-color', 'padding-top', 'margin-top', 'min-width', 'min-height'];
			const out = {
				toast: cs(toasts.querySelector('.bck-toast__close'), props),
				dialog: cs(dlg.querySelector('.bck-dialog__close'), props),
			};
			toasts.remove();
			dlg.remove();
			return out;
		});
		for (const [name, s] of Object.entries(probe)) {
			expect(s['background-color'], `${name} close must stay transparent`).toBe('rgba(0, 0, 0, 0)');
			expect(s['background-image'], `${name} close must not take core gradient`).toBe('none');
		}
		// Toast close pins border:0; dialog close keeps a 1px transparent
		// border so forced-colors/high-contrast still draws the ring.
		expect(probe.toast['border-top-width']).toBe('0px');
		expect(probe.dialog['border-top-width']).toBe('1px');
		expect(probe.dialog['border-top-color']).toBe('rgba(0, 0, 0, 0)');
		expect(parseFloat(probe.toast['min-width']), 'toast close min-width < 44px touch target').toBeGreaterThanOrEqual(44);
		expect(parseFloat(probe.dialog['min-width']), 'dialog close min-width < 44px touch target').toBeGreaterThanOrEqual(44);
		await page.context().close();
	});
});

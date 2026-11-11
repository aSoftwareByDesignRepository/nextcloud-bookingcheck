// @ts-check
/**
 * ATLAS web craft capture — transfer settings surfaces. Writes PNGs to
 * .cursor/atlas-farm-v3/artifacts/bookingcheck/craft/web/.
 * Flow: settings/transfer load → uniform file error → real
 * export→preview→report dialog → apply → progress dialog → after-apply.
 * Recaptured after the pack re-sync @d8c5a2e593: the rendered strings must
 * show interpolated values (never %d/%s/%1$s/{0} residue), the replace
 * token row must escape the NC-core 130px pin, and the dialog footer
 * (Cancel + Apply import) must paint inside the dialog client box at both
 * 1280 and 375 (required_field_or_step_undiscoverable donor fix).
 *
 * Session note: SessionCsrfMiddleware demands the session requesttoken on
 * every staff non-GET — API calls here go through the page's own
 * authenticated request context + OC.requestToken, never bare basic auth.
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const { login } = require('./helpers/auth.js');
const { assertAtlasRenderedSurface } = require('../../_shared/e2e/atlas-rendered-surface-contract.js');

const APP = '/index.php/apps/bookingcheck';
const PASS = 'atlas-e2e-passphrase-2026';
const OUT = path.resolve(__dirname, '../../../../.cursor/atlas-farm-v3/artifacts/bookingcheck/craft/web');
const CREDS = {
	username: process.env.E2E_USER || process.env.BCK_E2E_USER || 'bck_atlas',
	password: process.env.E2E_PASSWORD || process.env.BCK_E2E_PASS || 'BckAtlas2026!',
};
fs.mkdirSync(OUT, { recursive: true });

function occ(args) {
	return execSync(
		`docker compose -f /home/alex/Development/nextcloud-dev/nextcloud/docker-compose.yml exec -T -u www-data nextcloud php occ ${args}`,
		{ encoding: 'utf8', timeout: 60000 }).trim();
}
function db(sql) {
	return execSync(
		`docker compose -f /home/alex/Development/nextcloud-dev/nextcloud/docker-compose.yml exec -T mariadb mariadb -unextcloud -pnextcloud_password nextcloud -N -e "${sql.replace(/"/g, '\\"')}"`,
		{ encoding: 'utf8', timeout: 30000 }).trim();
}
async function shot(page, name) {
	await page.screenshot({ path: path.join(OUT, `${name}.png`), fullPage: false });
}
/** Authenticated request through the page's own cookie jar + the session
 * CSRF token the app middleware demands on non-GET. */
async function authedPost(page, path, data) {
	const token = await page.evaluate(() => (window.OC && OC.requestToken) || '');
	return page.context().request.post(path, {
		data,
		headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
	});
}

test.describe('craft transfer', () => {
	test('staff transfer surface + report/progress dialogs 1280/375', async ({ page }) => {
		test.setTimeout(240_000);

		await page.setViewportSize({ width: 1280, height: 800 });
		await login(page, CREDS);
		await page.goto(`${APP}/settings/transfer`, { waitUntil: 'domcontentloaded' });
		await expect(page.locator('#bck-transfer-export-card')).toBeVisible({ timeout: 20000 });

		const exp = await authedPost(page, `${APP}/api/admin/transfer/export`, { passphrase: PASS });
		expect(exp.status()).toBe(200);
		const bytes = await exp.body();
		expect(bytes.length).toBeGreaterThan(200);

		await page.waitForTimeout(600);
		await shot(page, 'bck-staff-transfer-light-1280');

		// uniform file error — wrong passphrase on a real container
		await page.locator('#bck-transfer-file').setInputFiles({
			name: 't.nctransfer', mimeType: 'application/json', buffer: bytes,
		});
		await page.locator('#bck-transfer-import-passphrase').fill('wrong-passphrase-000');
		await expect(page.locator('#bck-transfer-result-live')).not.toBeEmpty({ timeout: 30000 });
		await shot(page, 'bck-staff-transfer-fileerror-light-1280');

		// correct passphrase → preview → report dialog (interpolated counters)
		await page.locator('#bck-transfer-import-passphrase').fill(PASS);
		const report = page.locator('#bck-transfer-report');
		await expect(report).toBeVisible({ timeout: 30000 });
		await expect(page.locator('#bck-transfer-report-verdict')).toContainText(/\d/);
		// positional t() interpolation: localized values, never raw tokens
		const fileLine = await page.locator('#bck-transfer-report-file').textContent();
		expect(fileLine || '').not.toMatch(/\{\d+\}|%\d+\$s/);
		await shot(page, 'bck-staff-transfer-report-dialog-light-1280');

		// apply → progress dialog stays open while the job waits queued
		await page.locator('#bck-transfer-confirm').click();
		const progress = page.locator('#bck-transfer-progress');
		await expect(progress).toBeVisible({ timeout: 10000 });
		await expect(page.locator('#bck-transfer-progress-text')).not.toBeEmpty();
		await shot(page, 'bck-staff-transfer-progress-light-1280');

		// app-namespaced class id — other apps' TransferApplyJob rows never match
		const jobId = db("SELECT id FROM oc_jobs WHERE class LIKE '%BookingCheck%TransferApplyJob%' ORDER BY id DESC LIMIT 1");
		expect(jobId).toMatch(/^\d+$/);
		const priorReceiptId = (((await (await page.context().request.get(`${APP}/api/admin/transfer/status`, { headers: { 'OCS-APIRequest': 'true' } })).json()).data || {}).lastReceipt || {}).id || '';
		occ(`background-job:execute ${jobId}`);

		let receipt = {};
		for (let i = 0; i < 60; i++) {
			const s = await page.context().request.get(`${APP}/api/admin/transfer/status`, { headers: { 'OCS-APIRequest': 'true' } });
			receipt = (((await s.json()).data || {}).lastReceipt) || {};
			if (receipt.id && receipt.id !== priorReceiptId
				&& (receipt.result === 'applied' || receipt.result === 'failed')) break;
			await new Promise((r) => setTimeout(r, 1000));
		}
		expect(receipt.result).toBe('applied');

		await page.waitForLoadState('domcontentloaded');
		await expect(page.locator('#bck-transfer-last')).toBeVisible({ timeout: 20000 });
		await page.waitForTimeout(600);
		await shot(page, 'bck-staff-transfer-after-apply-light-1280');
	});

	test('staff transfer surface mobile 375', async ({ page }) => {
		await page.setViewportSize({ width: 375, height: 812 });
		await login(page, CREDS);
		await page.goto(`${APP}/settings/transfer`, { waitUntil: 'domcontentloaded' });
		await expect(page.locator('#bck-transfer-export-card')).toBeVisible({ timeout: 20000 });
		await page.waitForTimeout(600);
		await shot(page, 'bck-staff-transfer-light-375');
	});

	test('staff transfer replace-confirm token row wide + footer painted (375/1280)', async ({ page }) => {
		test.setTimeout(180_000);

		await page.setViewportSize({ width: 1280, height: 800 });
		await login(page, CREDS);
		await page.goto(`${APP}/settings/transfer`, { waitUntil: 'domcontentloaded' });
		await expect(page.locator('#bck-transfer-export-card')).toBeVisible({ timeout: 20000 });

		const exp = await authedPost(page, `${APP}/api/admin/transfer/export`, { passphrase: PASS });
		expect(exp.status()).toBe(200);
		const bytes = await exp.body();
		expect(bytes.length).toBeGreaterThan(200);

		// Full-form pinned-control sweep (cramped_form_controls).
		await assertAtlasRenderedSurface(page, {
			content: '#bck-main-content',
		});

		// Replace mode → real preview → report dialog footer shows token row.
		await page.locator('#bck-transfer-import-form input[type="radio"][value="replace"]').check();
		await page.locator('#bck-transfer-file').setInputFiles({
			name: 't.nctransfer', mimeType: 'application/json', buffer: bytes,
		});
		await page.locator('#bck-transfer-import-passphrase').fill(PASS);
		const report = page.locator('#bck-transfer-report');
		await expect(report).toBeVisible({ timeout: 30000 });
		const tokenRow = page.locator('#bck-transfer-replace-confirm');
		await expect(tokenRow).toBeVisible();
		const token = page.locator('#bck-transfer-replace-token');
		await expect(token).toBeVisible();

		// cramped_form_controls fix live: the input escapes the core 130px pin.
		const w = await token.evaluate((el) => el.getBoundingClientRect().width);
		expect(w, `token input ${Math.round(w)}px still on the NC-core 130px pin`).toBeGreaterThan(140);

		// required_field_or_step_undiscoverable: the report dialog's footer —
		// token row + Cancel + Apply import — must paint INSIDE the dialog's
		// client box at every viewport; .bck-dialog__body absorbs overflow.
		const assertDialogFooterPainted = async (where) => {
			const g = await report.evaluate((d) => {
				const cs = getComputedStyle(d);
				const r = d.getBoundingClientRect();
				const bt = parseFloat(cs.borderTopWidth) || 0;
				const bl = parseFloat(cs.borderLeftWidth) || 0;
				const client = { top: r.top + bt, left: r.left + bl, bottom: r.top + bt + d.clientHeight, right: r.left + bl + d.clientWidth };
				const f = d.querySelector('.bck-dialog__footer').getBoundingClientRect();
				const a = d.querySelector('#bck-transfer-confirm').getBoundingClientRect();
				return { client, dScrollH: d.scrollHeight, dClientH: d.clientHeight,
					footer: { top: f.top, bottom: f.bottom, left: f.left, right: f.right },
					apply: { top: a.top, bottom: a.bottom, left: a.left, right: a.right } };
			});
			for (const [name, box] of Object.entries({ footer: g.footer, apply: g.apply })) {
				expect(box.bottom, `${where}: ${name} bottom ${Math.round(box.bottom)} hangs below dialog client box ${Math.round(g.client.bottom)}`).toBeLessThanOrEqual(g.client.bottom + 0.5);
				expect(box.top, `${where}: ${name} top above dialog client box`).toBeGreaterThanOrEqual(g.client.top - 0.5);
				expect(box.right, `${where}: ${name} right outside dialog client box`).toBeLessThanOrEqual(g.client.right + 0.5);
				expect(box.left, `${where}: ${name} left outside dialog client box`).toBeGreaterThanOrEqual(g.client.left - 0.5);
			}
			expect(g.dScrollH - g.dClientH,
				`${where}: dialog shell scrolls (scrollHeight ${g.dScrollH} > clientHeight ${g.dClientH}) — .bck-dialog__body must absorb the overflow`)
				.toBeLessThanOrEqual(1);
		};
		await assertDialogFooterPainted('1280');

		// Dialog-open sweep — listAllow: .bck-transfer-group uls are mono-font
		// machine-name manifest readouts inside <details> disclosures —
		// marker-less by design (same class as the ptl r24/r25 adjudication).
		await assertAtlasRenderedSurface(page, {
			content: '#bck-main-content',
			listAllow: '.bck-transfer-group',
		});

		await shot(page, 'bck-staff-transfer-replace-confirm-light-1280');
		await page.setViewportSize({ width: 375, height: 812 });
		await page.waitForTimeout(300);
		await assertDialogFooterPainted('375');
		await shot(page, 'bck-staff-transfer-replace-confirm-light-375');
		// Never apply — replace is destructive; close the dialog instead.
		await page.keyboard.press('Escape');
		await expect(report).toBeHidden();
	});
});

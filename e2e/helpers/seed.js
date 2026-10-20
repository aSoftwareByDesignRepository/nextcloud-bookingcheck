// Seed a published event offering through the staff REST API.
// Uses the page's session cookies + the requesttoken from the rendered
// document head — the same contract the app's own JS client uses.
async function seedOffering(page, { title, priceCents = 0, capacity = 5 }) {
	const base = new URL(page.url()).origin;
	await page.goto('/apps/bookingcheck/offerings', { waitUntil: 'domcontentloaded' });
	const token = await page.evaluate(
		() => document.head?.dataset?.requesttoken ?? '',
	);
	if (!token) {
		throw new Error('no requesttoken — staff session missing');
	}
	const headers = {
		requesttoken: token,
		'OCS-APIRequest': 'true',
		Accept: 'application/json',
	};

	const create = await page.request.post(`${base}/apps/bookingcheck/api/offerings`, {
		headers,
		form: {
			title,
			type: 'event',
			price_cents: String(priceCents),
			currency: 'EUR',
			vat_bp: '1900',
		},
	});
	if (!create.ok()) {
		throw new Error(`offering create failed: ${create.status()} ${await create.text()}`);
	}
	const body = await create.json();
	const id = body.data.id;
	const slug = body.data.slug;

	const start = new Date(Date.now() + 30 * 86400_000)
		.toISOString().slice(0, 19).replace('T', ' ');
	const end = new Date(Date.now() + 30 * 86400_000 + 7200_000)
		.toISOString().slice(0, 19).replace('T', ' ');
	const ev = await page.request.post(`${base}/apps/bookingcheck/api/offerings/${id}/slots`, {
		headers,
		form: { starts_at: start, ends_at: end, capacity: String(capacity) },
	});
	if (!ev.ok()) {
		throw new Error(`event create failed: ${ev.status()} ${await ev.text()}`);
	}
	const eventId = (await ev.json()).data.id;

	const pub = await page.request.post(
		`${base}/apps/bookingcheck/api/offerings/${id}/transition`,
		{ headers, form: { to: 'published' } },
	);
	if (!pub.ok()) {
		throw new Error(`publish failed: ${pub.status()} ${await pub.text()}`);
	}
	return { id, slug, eventId };
}

async function destroyOffering(page, id) {
	const base = new URL(page.url()).origin;
	const token = await page.evaluate(
		() => document.head?.dataset?.requesttoken ?? '',
	);
	await page.request.delete(`${base}/apps/bookingcheck/api/offerings/${id}`, {
		headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
	});
}

// a staff context wired to the storage state global-setup writes —
// browser.newPage() alone gives an anonymous context
const path = require('path');
const fs = require('fs');

function staffStorageState() {
	const p = process.env.E2E_STORAGE_STATE
		|| path.join(__dirname, '..', '.auth', 'storage-state.json');
	return fs.existsSync(p) ? p : undefined;
}

async function staffPage(browser) {
	const ctx = await browser.newContext({ storageState: staffStorageState() });
	const page = await ctx.newPage();
	await page.goto('/', { waitUntil: 'domcontentloaded' });
	return page;
}

module.exports = { seedOffering, destroyOffering, staffPage, staffStorageState };

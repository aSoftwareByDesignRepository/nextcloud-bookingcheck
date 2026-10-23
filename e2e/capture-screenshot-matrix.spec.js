// @ts-check
/**
 * ATLAS screenshot-matrix capture (design-system checklist §VISUAL gate).
 *
 * Drives every surface in e2e/screenshot-matrix.json through the declared
 * viewport × state × theme grid and writes PNGs into
 * .cursor/atlas-farm-v3/artifacts/bookingcheck/craft/web/. The companion
 * gate check-screenshot-matrix.py verifies completeness — a declared cell
 * that produces no artifact fails the ready-gate craft stage.
 *
 * States:
 *   populated — seeded data visible (published offering with slot, a
 *               completed guest order, waitlist join, voucher, promo,
 *               resource, review; audit/webhook pages accumulate entries
 *               from the seed actions themselves)
 *   current   — state-invariant chrome (forms, settings, doors)
 *
 * BookingCheck list pages render their empty state server-side and have
 * no in-page filters — a deterministic "empty" render would need a
 * pristine database, so data surfaces declare `populated` only (house
 * convention, same as tourcheck pill lists).
 *
 * Seed chain (all real server paths, nothing stubbed):
 *   staff API  → offering + slot + publish; voucher/promo/resource rows
 *   guest API  → free order → manage token, ticket, order ref
 *   license    → BCK2 Pro must be installed (e2e/setup-env does it); Pro
 *                surfaces (waitlist/claim/portal/embed) render real UI
 *   claim      → capacity-1 allocate offering, seat filled, waitlist join,
 *                staff cancel → offerSeat mints the token, EmailQueueJob
 *                drained on demand → Mailhog yields the claim URL
 *   portal     → /pub/api/portal/send → magic link via Mailhog → session
 *
 * Run:
 *   E2E_USER=bck_atlas E2E_PASSWORD=… npx playwright test e2e/capture-screenshot-matrix.spec.js
 */
const { test, expect } = require('@playwright/test')
const { existsSync, mkdirSync, readFileSync, statSync, writeFileSync } = require('node:fs')
const { join, resolve } = require('node:path')
const { login } = require('./helpers/auth.js')
const { gotoApp } = require('./helpers/app.js')
const { setUserTheme, resetUserTheme } = require('./helpers/theming.js')
const { occ, runJob, mailBody, extractUrl } = require('./helpers/nc.js')

const HERE = __dirname
const MANIFEST = JSON.parse(readFileSync(join(HERE, 'screenshot-matrix.json'), 'utf8'))
// Lab-only BCK2 fixture — honored only under BCK_ALLOW_VENDOR_KEY_OVERRIDE.
const LICENSE_FIXTURE = require('../tests/fixtures/license_bck2_golden.json')
const OUT = resolve(HERE, '../../../../.cursor/atlas-farm-v3/artifacts/bookingcheck', MANIFEST.outDir)
const APP = '/apps/bookingcheck'
// Env names mirror global-setup.js (E2E_USER/E2E_PASSWORD); BCK_E2E_* kept
// as aliases. The capture user must be an app admin — the in-app settings
// surface (/apps/bookingcheck/settings/*) refuses everyone else.
const ADMIN = {
	username: process.env.E2E_USER || process.env.BCK_E2E_USER || 'bck_atlas',
	password: process.env.E2E_PASSWORD || process.env.E2E_PASS || process.env.BCK_E2E_PASS || 'BckAtlas2026!',
}
const RUN = Date.now().toString(36)
const mail = (/** @type {string} */ local) => `bck-mx-${local}-${RUN}@example.test`
const BUYER_EMAIL = mail('buyer')
const WAITLIST_EMAIL = mail('wait')

/** @type {{offeringId:number,slug:string,eventId:number,orderId:number,manageToken:string,publicRef:string,ticketId:number,lineId:number,claimToken:string,pspRef:string,portalMagic:string,claimOfferingId:number,claimOrderId:number,paidOfferingId:number}} */
const seed = {
	offeringId: 0, slug: '', eventId: 0, orderId: 0, manageToken: '', publicRef: '',
	ticketId: 0, lineId: 0, claimToken: '', pspRef: '', portalMagic: '',
	claimOfferingId: 0, claimOrderId: 0, paidOfferingId: 0,
}
/** psp_provider captured before the run mutates it — restored in afterAll. */
let origPspProvider = ''
let pspTouched = false
/** license_key captured before the run mutates it — restored in afterAll. */
let origLicense = ''
let licenseTouched = false
const written = []

async function reqToken(/** @type {import('@playwright/test').Page} */ page) {
	return page.evaluate(() =>
		(window.OC && window.OC.requestToken)
		|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
		|| '')
}

async function api(
	/** @type {import('@playwright/test').Page} */ page,
	/** @type {string} */ method,
	/** @type {string} */ path,
	/** @type {Record<string, unknown> | undefined} */ data) {
	const token = await reqToken(page)
	const res = await page.request.fetch(`${APP}${path}`, {
		method,
		form: data && Object.fromEntries(Object.entries(data).map(([k, v]) => [k, String(v)])),
		headers: { requesttoken: token, 'OCS-APIRequest': 'true', Accept: 'application/json' },
	})
	return res.json().catch(() => ({ success: false }))
}

/**
 * Public guest POST — no requesttoken needed (NoCSRF public routes),
 * but the origin header keeps SameSite/cors guards happy.
 */
async function pubApi(
	/** @type {import('@playwright/test').Page} */ page,
	/** @type {string} */ path,
	/** @type {Record<string, unknown>} */ data) {
	const res = await page.request.fetch(`${APP}${path}`, {
		method: 'POST',
		form: Object.fromEntries(Object.entries(data).map(([k, v]) => [k, String(v)])),
		headers: { Accept: 'application/json' },
	})
	return res.json().catch(() => ({ success: false, status: res.status() }))
}

/** Order create is idempotent on `idem_token` — [A-Za-z0-9_-]{8,36}. */
function idem() {
	return `mx${Date.now().toString(36)}${Math.random().toString(36).slice(2, 14)}`.replace(/[^A-Za-z0-9_-]/g, '').slice(0, 36)
}

/**
 * occ config writes hit the DB instantly, but web workers may keep serving a
 * stale APCu appconfig blob for up to LOCAL_CACHE_TTL (3s): the CLI APCu
 * segment is not shared with mod_php, so occ's clearCache() cannot evict the
 * web copy. Poll the web-read path until it agrees before touching any route
 * that depends on the key.
 */
async function appConfig(
	/** @type {import('@playwright/test').Page} */ page,
	/** @type {string} */ key,
	/** @type {string} */ value,
	/** @type {string} */ section = 'payments') {
	if (value === '') {
		occ(['config:app:delete', 'bookingcheck', key])
	} else {
		occ(['config:app:set', 'bookingcheck', key, `--value=${value}`])
	}
	for (let i = 0; i < 40; i++) {
		const s = await api(page, 'GET', `/api/settings/${section}`)
		if ((s.data?.settings?.[key] ?? '') === value) { return }
		await new Promise((r) => setTimeout(r, 250))
	}
	throw new Error(`bookingcheck.${key} did not propagate to web reads (APCu appconfig staleness)`)
}

/** Web-visible read of one appconfig key (same path appConfig() polls). */
async function appConfigGet(
	/** @type {import('@playwright/test').Page} */ page,
	/** @type {string} */ key,
	/** @type {string} */ section = 'payments') {
	const s = await api(page, 'GET', `/api/settings/${section}`)
	return (s.data?.settings?.[key] ?? '')
}

/**
 * `#app-content` scrolls internally — a viewport-height shot only captures
 * the fold. Grow the viewport to full content height before shooting.
 * Public guest surfaces scroll inside fixed-height containers instead:
 * #body-public pins body and clips #content, and .bck-pub/.bck-embed are
 * the internal scrollers — documentElement.scrollHeight can't see that
 * overflow, so measure every candidate scroller's top + scrollHeight.
 */
async function shot(/** @type {import('@playwright/test').Page} */ page, /** @type {string} */ name, /** @type {number} */ vpHeight) {
	const contentH = await page.evaluate(() => {
		let h = (document.querySelector('#app-content') || document.documentElement).scrollHeight
		for (const sel of ['#content', '.bck-pub', '.bck-embed']) {
			const el = document.querySelector(sel)
			if (!el) { continue }
			h = Math.max(h, Math.ceil(el.getBoundingClientRect().top) + el.scrollHeight)
		}
		return h
	})
	const shotH = Math.min(Math.max(contentH + 60, vpHeight), 8192)
	if (shotH !== vpHeight) {
		await page.setViewportSize({ width: page.viewportSize()?.width || 1280, height: shotH })
		await page.evaluate(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))))
	}
	const file = join(OUT, `${name}.png`)
	await page.screenshot({ path: file })
	const size = existsSync(file) ? statSync(file).size : 0
	expect(size, `${name}.png too small — likely blank/error frame`).toBeGreaterThan(4096)
	written.push({ id: name, path: `craft/web/${name}.png`, bytes: size })
}

function resolvePath(/** @type {string} */ p) {
	return p
		.replace('{offeringId}', String(seed.offeringId))
		.replace('{orderId}', String(seed.orderId))
		.replace('{slug}', seed.slug)
		.replace('{manageToken}', seed.manageToken)
		.replace('{ticketId}', String(seed.ticketId))
		.replace('{publicRef}', seed.publicRef)
		.replace('{claimToken}', seed.claimToken)
		.replace('{pspRef}', seed.pspRef)
}

function surfaceUrl(/** @type {Record<string, unknown>} */ surface) {
	const path = resolvePath(/** @type {string} */ (surface.path))
	return surface.ncRoot ? path : `${APP}${path}`
}

function shotName(/** @type {string} */ sid, /** @type {string} */ state, /** @type {string} */ vpName, /** @type {string} */ theme) {
	return `${MANIFEST.prefix}-${sid}-${state}-${vpName}${theme === 'dark' ? '-dark' : ''}`
}

function* matrixCells(/** @type {string} */ theme) {
	for (const surface of MANIFEST.surfaces) {
		for (const state of surface.states) {
			for (const vp of MANIFEST.viewports) {
				if (theme === 'dark' && (!surface.dark || vp.name !== 'desktop' || state !== surface.states[0])) { continue }
				yield { surface, state, vp }
			}
		}
	}
}

test.describe('SCREENSHOT_MATRIX capture', () => {
	test.setTimeout(600_000)

	test.beforeAll(async ({ browser }) => {
		test.setTimeout(600_000) // hooks ignore the describe-level timeout
		const ctx = await browser.newContext()
		const page = await ctx.newPage()
		try {
			await login(page, ADMIN)
			await gotoApp(page, `${APP}/overview`)

			// Sandbox PSP so a paid order can be minted without live keys.
			// Web workers may serve a stale appconfig blob for ~3s after the
			// occ write — poll until the web-read path sees it (16:34 failure).
			origPspProvider = await appConfigGet(page, 'psp_provider')
			pspTouched = true
			await appConfig(page, 'psp_provider', 'test')

			// Pro license is required for reviews/portal/uploads cells — do not
			// trust prior suite state to have left one installed (a wiped key
			// fails silently as "no line id on manage page"). Install the
			// golden fixture and restore whatever was there in afterAll.
			origLicense = await appConfigGet(page, 'license_key', 'general')
			licenseTouched = true
			// PUT echoes the live license verdict — assert the fixture is
			// honored rather than discovering free tier at the review cell.
			const licPut = await api(page, 'PUT', '/api/settings/general', { license_key: LICENSE_FIXTURE.wireKey })
			expect(licPut.data?.license?.state, 'BCK2 fixture not honored (BCK_ALLOW_VENDOR_KEY_OVERRIDE off?)').toBe('pro')

			// ── Primary offering: free event → instant 'done' order ──
			const off = await api(page, 'POST', '/api/offerings', {
				title: `MX Concert ${Date.now()}`, type: 'event', price_cents: 0,
				currency: 'EUR', vat_bp: 1900, review_display: 1, list_in_directory: 1,
				descr: 'Matrix seed event — an evening of seeded bookings.',
				// embed_domains empty → /pub/embed/o/{slug} renders PublicNotFound
				// and the pub-embed 'populated' cell captures a 404 card instead
				// of the widget. Any valid https origin unlocks the surface.
				embed_domains: 'https://localhost',
			})
			expect(off.success, `offering seed: ${JSON.stringify(off)}`).toBe(true)
			seed.offeringId = off.data.id
			seed.slug = off.data.slug
			const start = new Date(Date.now() + 30 * 86400_000).toISOString().slice(0, 19).replace('T', ' ')
			const end = new Date(Date.now() + 30 * 86400_000 + 7200_000).toISOString().slice(0, 19).replace('T', ' ')
			const ev = await api(page, 'POST', `/api/offerings/${seed.offeringId}/slots`, {
				starts_at: start, ends_at: end, capacity: 5,
			})
			expect(ev.success, `slot seed: ${JSON.stringify(ev)}`).toBe(true)
			seed.eventId = ev.data.id
			const pub = await api(page, 'POST', `/api/offerings/${seed.offeringId}/transition`, { to: 'published' })
			expect(pub.success, `publish: ${JSON.stringify(pub)}`).toBe(true)

			// ── Allocate-mode offering (claim-token chain) ──
			const off2 = await api(page, 'POST', '/api/offerings', {
				title: `MX Workshop ${Date.now()}`, type: 'event', price_cents: 0,
				currency: 'EUR', vat_bp: 1900, waitlist_mode: 'allocate',
			})
			expect(off2.success, `claim offering: ${JSON.stringify(off2)}`).toBe(true)
			seed.claimOfferingId = off2.data.id
			const ev2 = await api(page, 'POST', `/api/offerings/${off2.data.id}/slots`, {
				starts_at: start, ends_at: end, capacity: 1,
			})
			expect(ev2.success, `claim slot: ${JSON.stringify(ev2)}`).toBe(true)
			await api(page, 'POST', `/api/offerings/${off2.data.id}/transition`, { to: 'published' })

			// ── Priced offering → sandbox PSP order → sandbox surface ──
			const off3 = await api(page, 'POST', '/api/offerings', {
				title: `MX Gala ${Date.now()}`, type: 'event', price_cents: 2999,
				currency: 'EUR', vat_bp: 1900,
			})
			expect(off3.success, `paid offering: ${JSON.stringify(off3)}`).toBe(true)
			seed.paidOfferingId = off3.data.id
			const ev3 = await api(page, 'POST', `/api/offerings/${off3.data.id}/slots`, {
				starts_at: start, ends_at: end, capacity: 4,
			})
			expect(ev3.success, `paid slot: ${JSON.stringify(ev3)}`).toBe(true)
			await api(page, 'POST', `/api/offerings/${off3.data.id}/transition`, { to: 'published' })

			// ── Seller-side rows ──
			const v = await api(page, 'POST', '/api/vouchers', { credit_cents: 2500, max_uses: 3, currency: 'EUR' })
			expect(v.success, `voucher: ${JSON.stringify(v)}`).toBe(true)
			const pr = await api(page, 'POST', '/api/promos', { code: `MX${Date.now() % 100000}`, kind: 'percent', percent_bps: 1000 })
			expect(pr.success, `promo: ${JSON.stringify(pr)}`).toBe(true)
			const rs = await api(page, 'POST', '/api/resources', { offering_id: seed.offeringId, name: `MX Room ${Date.now()}`, capacity: 2 })
			expect(rs.success, `resource: ${JSON.stringify(rs)}`).toBe(true)

			// ── Guest order on the free event ──
			const order = await pubApi(page, '/pub/api/orders', {
				offering_id: seed.offeringId, buyer_name: 'Matrix Buyer', buyer_email: BUYER_EMAIL,
				locale: 'en', consent: '1', idem_token: idem(),
				'lines[0][target_type]': 'event', 'lines[0][target_id]': seed.eventId, 'lines[0][seats]': 2,
			})
			expect(order.success, `guest order: ${JSON.stringify(order)}`).toBe(true)
			seed.publicRef = order.data.order_ref
			const mUrl = /** @type {string} */ (order.data.manage_url || '')
			const mTok = mUrl.match(/\/pub\/m\/([^/?]+)/)
			expect(mTok, 'manage_url lacks token').toBeTruthy()
			seed.manageToken = mTok[1]

			// orderId + ticketId + lineId via rendered pages (no JSON detail API)
			const ordersHtml = await (await page.request.get(`${APP}/orders`)).text()
			const idM = ordersHtml.match(new RegExp(`orders/(\\d+)[^>]*>\\s*${seed.publicRef.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`))
			expect(idM, 'order id not found on /orders').toBeTruthy()
			seed.orderId = Number(idM[1])
			const manageHtml = await (await page.request.get(`${APP}/pub/m/${seed.manageToken}`)).text()
			const tM = manageHtml.match(/\/tickets\/(\d+)/)
			expect(tM, 'no ticket link on manage page').toBeTruthy()
			seed.ticketId = Number(tM[1])
			const lM = manageHtml.match(/data-line-id="(\d+)"/)
			expect(lM, 'no line id on manage page').toBeTruthy()
			seed.lineId = Number(lM[1])

			// ── Review (paid order + review_display on the offering) ──
			const rv = await pubApi(page, '/pub/api/reviews', {
				manage_token: seed.manageToken, line_id: seed.lineId, rating: 5,
				text: 'Brilliant evening — entry was quick and friendly.',
			})
			expect(rv.success, `review: ${JSON.stringify(rv)}`).toBe(true)

			// ── Waitlist row on the primary event (visible in /waitlist) ──
			await pubApi(page, '/pub/api/waitlist', {
				target_type: 'event', target_id: seed.eventId, email: mail('wait2'), locale: 'en',
			})

			// ── Claim chain: fill cap-1 event, queue a guest, release ──
			const o2 = await pubApi(page, '/pub/api/orders', {
				offering_id: seed.claimOfferingId, buyer_name: 'Seat Holder', buyer_email: mail('hold'),
				locale: 'en', consent: '1', idem_token: idem(),
				'lines[0][target_type]': 'event', 'lines[0][target_id]': ev2.data.id, 'lines[0][seats]': 1,
			})
			expect(o2.success, `claim seat order: ${JSON.stringify(o2)}`).toBe(true)
			const o2Ref = o2.data.order_ref
			const ordersHtml2 = await (await page.request.get(`${APP}/orders`)).text()
			const id2M = ordersHtml2.match(new RegExp(`orders/(\\d+)[^>]*>\\s*${o2Ref}`))
			expect(id2M, 'claim order id not on /orders').toBeTruthy()
			seed.claimOrderId = Number(id2M[1])
			await pubApi(page, '/pub/api/waitlist', {
				target_type: 'event', target_id: ev2.data.id, email: WAITLIST_EMAIL, locale: 'en',
			})
			const cancel = await api(page, 'POST', `/api/orders/${seed.claimOrderId}/cancel`, { action: 'keep' })
			expect(cancel.success, `claim cancel: ${JSON.stringify(cancel)}`).toBe(true)

			// ── Portal magic link for the buyer ──
			await pubApi(page, '/pub/api/portal/send', { email: BUYER_EMAIL, locale: 'en' })

			// ── Paid order through the sandbox PSP → /pub/sandbox/pay ──
			const o3 = await pubApi(page, '/pub/api/orders', {
				offering_id: seed.paidOfferingId, buyer_name: 'Sandbox Buyer', buyer_email: mail('paid'),
				locale: 'en', consent: '1', idem_token: idem(),
				'lines[0][target_type]': 'event', 'lines[0][target_id]': ev3.data.id, 'lines[0][seats]': 1,
			})
			expect(o3.success, `sandbox order: ${JSON.stringify(o3)}`).toBe(true)
			const checkoutUrl = /** @type {string} */ (o3.data.checkout_url || '')
			const refM = checkoutUrl.match(/\/sandbox\/pay\/([^/?]+)/)
			expect(refM, `checkout_url not a sandbox link: ${checkoutUrl}`).toBeTruthy()
			seed.pspRef = refM[1]

			// ── Drain the mail queue → claim + portal tokens via Mailhog ──
			runJob('EmailQueueJob')
			const claimMail = await mailBody(/\/pub\/claim\//, { to: WAITLIST_EMAIL })
			const claimUrl = extractUrl(claimMail, /https?:\/\/[^\s"'<]+?\/pub\/claim\/[A-Za-z0-9_-]+/)
			seed.claimToken = claimUrl.split('/pub/claim/')[1]
			const portalMail = await mailBody(/\/pub\/portal\/magic\//, { to: BUYER_EMAIL })
			const portalUrl = extractUrl(portalMail, /https?:\/\/[^\s"'<]+?\/pub\/portal\/magic\/[A-Za-z0-9_-]+/)
			seed.portalMagic = portalUrl
		} finally {
			await ctx.close()
		}
	})

	test.afterAll(async ({ browser }) => {
		const ctx = await browser.newContext()
		const page = await ctx.newPage()
		try {
			await login(page, ADMIN)
			await gotoApp(page, `${APP}/overview`)
			for (const id of [seed.offeringId, seed.claimOfferingId, seed.paidOfferingId]) {
				if (id) {
					// Delete when nothing sold; archive when orders exist —
					// the product refuses to delete sold inventory and a
					// published corpse stays visible in catalog + staff lists.
					const d = await api(page, 'DELETE', `/api/offerings/${id}`)
					if (!d.success) {
						await api(page, 'POST', `/api/offerings/${id}/transition`, { to: 'archived' })
					}
				}
			}
			await resetUserTheme(page).catch(() => {})
		} finally {
			await ctx.close()
			// Restore, not delete — the shared instance's suite expects the
			// sandbox provider to stay configured for the next spec run. And
			// only touch it if this run actually mutated it — a beforeAll
			// abort must not delete a key we never wrote.
			if (pspTouched) {
				if (origPspProvider === '') {
					occ(['config:app:delete', 'bookingcheck', 'psp_provider'])
				} else {
					occ(['config:app:set', 'bookingcheck', 'psp_provider', `--value=${origPspProvider}`])
				}
			}
			if (licenseTouched) {
				if (origLicense === '') {
					occ(['config:app:delete', 'bookingcheck', 'license_key'])
				} else {
					occ(['config:app:set', 'bookingcheck', 'license_key', `--value=${origLicense}`])
				}
			}
		}
	})

	test('captures every manifest cell', async ({ page, browser }) => {
		mkdirSync(OUT, { recursive: true })
		await login(page, ADMIN)

		// Public surfaces render for an anonymous guest context.
		let guestPage = null
		let portalPage = null
		const pageFor = async (surface) => {
			if (surface.needsPortal) {
				if (!portalPage) {
					const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } })
					portalPage = await ctx.newPage()
					// The magic link sets the portal session cookie — visit it,
					// then portal pages render the customer view.
					await portalPage.goto(seed.portalMagic, { waitUntil: 'domcontentloaded' })
				}
				return portalPage
			}
			if (!surface.public) { return page }
			if (!guestPage) {
				const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } })
				guestPage = await ctx.newPage()
			}
			return guestPage
		}
		const settleFor = (surface) => surface.settle || (surface.public ? '.bck-pub' : '#bck-main-content')

		// The theme is per-user server state — a killed dark run leaves the
		// fixture user dark, and the light pass would silently capture dark.
		// Navigate first: setUserTheme evaluates in-page and needs a real URL.
		await gotoApp(page, `${APP}/overview`, { contentSel: '#bck-main-content' })
		for (const theme of ['light', 'dark']) {
			await setUserTheme(page, theme)
			for (const { surface, state, vp } of matrixCells(theme)) {
				const target = await pageFor(surface)
				await target.setViewportSize({ width: vp.width, height: vp.height })
				await gotoApp(target, surfaceUrl(surface), { contentSel: settleFor(surface) })
				await target.evaluate(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))))
				await shot(target, shotName(surface.id, state, vp.name, theme), vp.height)
			}
		}
		if (guestPage) { await guestPage.context().close() }
		if (portalPage) { await portalPage.context().close() }
	})

	test('captures dialogs, forms, error states and theme variants', async ({ page, browser }) => {
		test.setTimeout(300_000)
		await login(page, ADMIN)
		await page.setViewportSize({ width: 1280, height: 800 })

		// ── Field errors on the offering create form (required title) ──
		await gotoApp(page, `${APP}/offerings/new`)
		const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first()
		if (await submitBtn.count()) {
			await submitBtn.click()
			const invalid = page.locator('[aria-invalid="true"]').first()
			if (await invalid.count()) {
				await shot(page, 'bck-offering-new-errors-desktop', 800)
			}
		}

		// ── High-contrast variants (matrix covers dark at desktop) ──
		await setUserTheme(page, 'light-highcontrast')
		await gotoApp(page, `${APP}/overview`, { contentSel: '#bck-main-content' })
		await shot(page, 'bck-overview-current-desktop-hc', 800)
		await gotoApp(page, `${APP}/orders`, { contentSel: '#bck-main-content' })
		await shot(page, 'bck-orders-populated-desktop-hc', 800)
		await resetUserTheme(page)

		// ── Uniform-404 door: page GETs answer a human HTML error page,
		//    API GETs answer the canonical JSON envelope — one body for
		//    missing and foreign entities (no existence oracle). ──
		const nfPage = await page.request.get(`${APP}/orders/999999999`)
		expect(nfPage.status()).toBe(404)
		expect(nfPage.headers()['content-type'] || '').toContain('text/html')
		const nfApi = await page.request.fetch(`${APP}/api/offerings/999999999`, {
			headers: { requesttoken: await reqToken(page), 'OCS-APIRequest': 'true', Accept: 'application/json' },
		})
		expect(nfApi.status()).toBe(404)
		const nfApiBody = await nfApi.json().catch(() => null)
		expect(nfApiBody?.error).toBe('NOT_FOUND')

		await gotoApp(page, `${APP}/orders/999999999`, { contentSel: '.body-login-container', retries: 0 })
		await shot(page, 'bck-order-not-found-desktop', 800)

		// Guest leg: a dead buyer link answers the branded not-found card
		// with a recovery CTA, not the browser's raw JSON view.
		const guest = await (await browser.newContext({ storageState: { cookies: [], origins: [] } })).newPage()
		try {
			const nfGuest = await guest.request.get(`${APP}/pub/m/bkc-mx-deadbeef/ics`)
			expect(nfGuest.status()).toBe(404)
			expect(nfGuest.headers()['content-type'] || '').toContain('text/html')
			await guest.setViewportSize({ width: 1280, height: 800 })
			await guest.goto(`${APP}/pub/m/bkc-mx-deadbeef/ics`, { waitUntil: 'domcontentloaded' })
			await guest.waitForSelector('.bck-pub', { timeout: 10_000 })
			await shot(guest, 'bck-pub-not-found-desktop', 800)
		} finally {
			await guest.context().close()
		}
	})

	test.afterAll(async () => {
		if (!written.length) { return }
		writeFileSync(join(OUT, '_manifest-ds-chrome.json'), JSON.stringify({
			app: 'bookingcheck',
			lane: 'ds_chrome',
			captured_at: new Date().toISOString(),
			manifest: 'nextcloud/apps/bookingcheck/e2e/screenshot-matrix.json',
			spec: 'nextcloud/apps/bookingcheck/e2e/capture-screenshot-matrix.spec.js',
			files: written,
		}, null, 1) + '\n')
	})
})

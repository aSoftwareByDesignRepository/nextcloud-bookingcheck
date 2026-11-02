// NC App Store screenshots — 1600x867 viewport shots (website convention).
// Seeded data must already exist (screenshot-matrix run provisions it).
// Run from the app dir: node e2e/store-shots.mjs
import { chromium } from 'playwright'
import { execFileSync } from 'node:child_process'

const APP = 'bookingcheck'
const BASE = (process.env.NC_BASE_URL || 'http://localhost:8081').replace(/\/$/, '')
const OUT = `screenshots/`
const CREDS = { username: process.env.BCK_E2E_USER || 'bck_atlas', password: process.env.BCK_E2E_PASS || 'BckAtlas2026!' }

const browser = await chromium.launch()
const ctx = await browser.newContext({ viewport: { width: 1600, height: 867 } })
const page = await ctx.newPage()

// request-context login (absolute URLs — no playwright config in play here)
const lp = await page.request.get(`${BASE}/login`)
const lt = (await lp.text()).match(/data-requesttoken="([^"]+)"/)[1]
const res = await page.request.post(`${BASE}/login`, {
	form: { user: CREDS.username, password: CREDS.password, requesttoken: decodeURIComponent(lt) },
	headers: { Origin: BASE }, maxRedirects: 0,
})
if (res.status() !== 303 || (res.headers()['location'] || '').startsWith('/login')) {
	throw new Error('Login rejected')
}
await page.goto(`${BASE}/apps/${APP}/`, { waitUntil: 'domcontentloaded' })
const rt = ((await page.content()).match(/data-requesttoken="([^"]+)"/) || [])[1] || ''

const api = async (p) => (await page.request.fetch(`${BASE}/apps/${APP}${p}`, { headers: { requesttoken: rt } })).json()

// resolve seeded ids
let offId = 0, ordId = 0, slug = ''
{
	// no list API exists — resolve seeded rows via the dev DB
	const q = (sql) => execFileSync('docker', ['exec', 'nextcloud-mariadb', 'mariadb',
		'-unextcloud', '-pnextcloud_password', 'nextcloud', '-sN', '-e', sql],
		{ encoding: 'utf8' }).trim().split('\n')[0] || ''
	const off = q("SELECT CONCAT(id,'|',slug) FROM oc_bc_offering WHERE status='published' ORDER BY id DESC LIMIT 1")
	;[offId, slug] = off.split('|')
	ordId = q("SELECT id FROM oc_bc_order ORDER BY id DESC LIMIT 1")
}

const shots = [['overview', '/apps/bookingcheck/overview'], ['offerings', '/apps/bookingcheck/offerings'], ['offering-detail', `/apps/bookingcheck/offerings/${offId}`], ['orders', '/apps/bookingcheck/orders'], ['order-detail', `/apps/bookingcheck/orders/${ordId}`], ['public-offering', `/apps/bookingcheck/pub/o/${slug}`]]

for (const [name, url] of shots) {
	await page.goto(`${BASE}${url}`, { waitUntil: 'domcontentloaded' })
	await page.waitForTimeout(2500)
	await page.screenshot({ path: `${OUT}${APP}-${name}.png` })
	console.log(`${name} <- ${url}`)
}
await browser.close()

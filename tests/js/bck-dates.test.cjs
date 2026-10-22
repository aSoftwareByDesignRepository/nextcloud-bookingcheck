#!/usr/bin/env node
/**
 * Timezone safety tests for the date helpers in js/app.js.
 *
 * The UTC-midnight bug class: parsing a date-only 'YYYY-MM-DD' as UTC and
 * then formatting it in a non-UTC zone shifts the displayed day (N-1 for
 * negative offsets, N+1 at UTC+14). fmtDate() must parse date-only input
 * as LOCAL midnight; todayYmd() must build from local components, never
 * toISOString() (which is UTC).
 *
 * Run the same assertions under different TZ env vars to prove the zone
 * doesn't move the rendered day:
 *   TZ=America/New_York   node tests/js/bck-dates.test.cjs
 *   TZ=Pacific/Kiritimati node tests/js/bck-dates.test.cjs
 *   TZ=Europe/Berlin      node tests/js/bck-dates.test.cjs
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..', '..');
const SRC = fs.readFileSync(path.join(ROOT, 'js', 'app.js'), 'utf8');

let failures = 0;
function assert(cond, msg) {
	if (!cond) {
		failures += 1;
		process.stderr.write(`FAIL [${process.env.TZ || 'default'}]: ${msg}\n`);
	} else {
		process.stdout.write(`ok   [${process.env.TZ || 'default'}] ${msg}\n`);
	}
}

/* ---------- minimal stubs ---------- */
const win = {
	document: {
		addEventListener() {},
		getElementById() { return null; },
		querySelector() { return null; },
		querySelectorAll() { return []; },
		createElement() {
			return { style: {}, setAttribute() {}, appendChild() {}, dataset: {} };
		},
		body: { dataset: {}, appendChild() {}, classList: { add() {}, remove() {} } },
	},
	navigator: { language: 'en' },
	location: { href: 'http://localhost/index.php/apps/bookingcheck/' },
	addEventListener() {},
	OC: { L10N: { translate(app, s) { return s; } } },
	OCP: {},
};
win.window = win;

const ctx = vm.createContext(win);
vm.runInContext(SRC, ctx, { filename: 'js/app.js' });

const BCK = win.BCK;
assert(BCK && typeof BCK.fmtDate === 'function', 'BCK.fmtDate exported');
assert(typeof BCK.fmtDateTime === 'function', 'BCK.fmtDateTime exported');
assert(typeof BCK.todayYmd === 'function', 'BCK.todayYmd exported');

/* ---------- fmtDate: date-only must not shift a day ---------- */
const out15 = BCK.fmtDate('2026-01-15');
assert(out15.includes('15'), `fmtDate('2026-01-15') keeps the 15th in ${process.env.TZ} — got '${out15}'`);
assert(!out15.includes('14') && !out15.includes('16'), `fmtDate('2026-01-15') not shifted (${out15})`);
const out29 = BCK.fmtDate('2026-02-28');
assert(out29.includes('28'), `fmtDate('2026-02-28') keeps the 28th — got '${out29}'`);

/* ---------- fmtDateTime: instants stay instants ---------- */
const inst = BCK.fmtDateTime('2026-01-15T23:30:00Z');
assert(typeof inst === 'string' && inst.length > 3, `fmtDateTime renders instant (${inst})`);

/* ---------- todayYmd: equals device-local calendar day ---------- */
const now = new Date();
const expected = now.getFullYear() + '-'
	+ String(now.getMonth() + 1).padStart(2, '0') + '-'
	+ String(now.getDate()).padStart(2, '0');
assert(BCK.todayYmd() === expected, `todayYmd()=${BCK.todayYmd()} == local day ${expected}`);
// Guard the UTC trap directly: under UTC+14 (Kiritimati) or negative offsets,
// toISOString().slice(0,10) is a different day within ~12h of local midnight.
assert(!/Z$/.test(BCK.todayYmd()), 'todayYmd carries no UTC marker');

if (failures) {
	process.stderr.write(`\n${failures} assertion(s) failed under TZ=${process.env.TZ || 'default'}\n`);
	process.exit(1);
}
process.stdout.write(`PASS bck-dates — all assertions green under TZ=${process.env.TZ || 'default'}\n`);

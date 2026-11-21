#!/usr/bin/env node
/**
 * Contract pin for js_sprintf_escape_passthrough (functional critic r19,
 * must_fix bkc-crit-r19-js-sprintf-escape-passthrough).
 *
 * Catalogs carry the vsprintf '%%' literal escape so PHP vsprintf renders a
 * single '%'. OC.L10N.translate returns catalog bytes verbatim ({name}/%n
 * only), so the JS helper must collapse '%%' itself. The pre-fix BCK.t
 * substituted via naive /%s/g and leaked '%%5 indirim.' to the buyer-facing
 * promo hint and '%%10.' to the admin promo dialog.
 *
 * Contract:
 *   1. tr 'Code applied: %s% off.' = 'Kod uygulandı: %%%s indirim.' renders
 *      'Kod uygulandı: %5 indirim.' through BCK.tf(['5']) — REAL catalog
 *      bytes, not a msgid-echo stub (echo masks the leak).
 *   2. tr 'In basis points — 1000 = 10 percent.' = '…= %%10.' renders
 *      '…= %10.' through BCK.t() with no vars.
 *   3. en bare-% cell 'Code applied: %s% off.' stays 'Code applied: 5% off.'
 *      — pinned so the JS-side contract for the unescaped value can't drift.
 *   4. Helper convention edges: '%%%s'->'%v', '%%'->'%', positional %N$[sd],
 *      %d specs, and {name} object-vars coexisting with %% collapse.
 *
 * Run: node tests/js/app-t-sprintf-escape.test.cjs
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
		process.stderr.write('FAIL: ' + msg + '\n');
	}
}
function eq(actual, expected, msg) {
	assert(actual === expected,
		msg + ' — expected ' + JSON.stringify(expected) + ', got ' + JSON.stringify(actual));
}

const catalogs = {};
function catalog(loc) {
	if (!catalogs[loc]) {
		catalogs[loc] = JSON.parse(
			fs.readFileSync(path.join(ROOT, 'l10n', loc + '.json'), 'utf8')
		).translations;
	}
	return catalogs[loc];
}

// Faithful OC.L10N.translate stub: returns REAL catalog bytes for the locale
// (msgid itself only on a catalog miss — never a blanket msgid echo).
function loadBCK(loc) {
	const tr = catalog(loc);
	const oc = {
		L10N: {
			translate(app, msgid) {
				return Object.prototype.hasOwnProperty.call(tr, msgid) ? tr[msgid] : msgid;
			}
		}
	};
	const sandbox = {
		window: { OC: oc },
		OC: oc,
		// Load-time DOM touch points in app.js (delegated print handler,
		// claim-form + sandbox-settle wiring) need only no-op lookups.
		document: {
			addEventListener() {},
			querySelector() { return null; },
			querySelectorAll() { return { forEach() {} }; },
		},
	};
	vm.createContext(sandbox);
	vm.runInContext(SRC, sandbox, { filename: 'js/app.js' });
	return sandbox.window.BCK;
}

// --- real-catalog cells: the two live leaks -----------------------------

const tr = loadBCK('tr');
const promo = tr.tf('Code applied: %s% off.', ['5']);
eq(promo, 'Kod uygulandı: %5 indirim.',
	"tr promo hint must collapse '%%' and substitute %s");
assert(!/%%/.test(promo), "tr promo hint must not contain '%%'");

const bps = tr.t('In basis points — 1000 = 10 percent.');
eq(bps, 'Baz puan cinsinden — 1000 = %10.',
	"tr basis-points hint must collapse '%%' with no vars");
assert(!/%%/.test(bps), "tr basis-points hint must not contain '%%'");

// en bare-% cell: '%s%' is a spec + literal %, not an escape — pinned.
const en = loadBCK('en');
eq(en.tf('Code applied: %s% off.', ['5']), 'Code applied: 5% off.',
	"en promo hint: '%s' substitutes, bare '%' survives untouched");

// --- helper-convention edges --------------------------------------------

// Escaped literal + spec adjacent: '%%%s' must tokenize %% then %s.
eq(tr.t('x %%%s y', ['5']), 'x %5 y', "array vars: '%%%s' -> '%5'");
eq(tr.t('tail %%'), 'tail %', "no vars: '%%' -> '%'");
eq(tr.t('100%% done', ['unused']), '100% done', "array vars: '%%' collapses too");
// Positional specs consume the indexed item, %d formats like %s.
eq(tr.t('%2$s of %1$s', ['a', 'b']), 'b of a', "positional %N$[sd]");
eq(tr.t('seat %d', ['7']), 'seat 7', "'%d' spec consumes an item");
// Object-vars path keeps {name} substitution AND collapses '%%' — collapse
// runs first so substituted values are never re-interpreted as escapes.
eq(tr.t('{who} pays 100%%', { who: 'Ann' }), 'Ann pays 100%',
	"object vars: '{name}' substitutes and '%%' collapses");
eq(tr.t('{who} 50%%', { who: 'x%%y' }), 'x%%y 50%',
	"substituted values keep literal '%' — no re-escape pass");
// Missing/empty vars array leaves specs without items untouched as ''.
eq(tr.t('%s', []), '', "empty array consumes specs as empty string");

if (failures) {
	process.stderr.write(failures + ' assertion(s) failed\n');
	process.exit(1);
}
process.stdout.write('OK app-t-sprintf-escape: all assertions passed\n');

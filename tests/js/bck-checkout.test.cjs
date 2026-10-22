#!/usr/bin/env node
/**
 * Behavioural tests for js/pages/public-checkout.js.
 *
 * Runs the real page script inside a vm context with a minimal DOM stub —
 * covers the availability clamp on qty, promo-percent reflected in the
 * live total, pre-submit validation into the field-error contract,
 * aria-busy hygiene, replay handling and pass-vs-lines payload shape.
 *
 * Run: node tests/js/bck-checkout.test.cjs
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..', '..');
const SRC = fs.readFileSync(path.join(ROOT, 'js', 'pages', 'public-checkout.js'), 'utf8');

let failures = 0;
function assert(cond, msg) {
	if (!cond) {
		failures += 1;
		process.stderr.write('FAIL: ' + msg + '\n');
	}
}

/* ---------- minimal DOM stub ---------- */

class FakeNode {
	constructor(tag) {
		this.tagName = (tag || '').toUpperCase();
		this.children = [];
		this.parentNode = null;
		this.attributes = {};
		this._text = '';
		this._listeners = {};
		this.disabled = false;
		this.checked = false;
		this.hidden = false;
		this.id = '';
		this._focused = 0;
	}
	get name() { return this.getAttribute('name'); } // DOM reflects name attr to property
	get value() { return this._value !== undefined ? this._value : (this.getAttribute('value') || ''); }
	set value(v) { this._value = String(v); }
	get textContent() { return this._text + this.children.map(c => c.textContent).join(''); }
	set textContent(v) { this.children = []; this._text = String(v); }
	getAttribute(k) { return k in this.attributes ? this.attributes[k] : null; }
	setAttribute(k, v) { this.attributes[k] = String(v); }
	removeAttribute(k) { delete this.attributes[k]; }
	appendChild(n) { n.parentNode = this; this.children.push(n); return n; }
	focus() { this._focused += 1; }
	addEventListener(type, fn) { (this._listeners[type] = this._listeners[type] || []).push(fn); }
	dispatch(type) {
		const ev = { target: this, _prevented: false, preventDefault() { this._prevented = true; } };
		let n = this; // bubble like the DOM does — form listeners see child events
		while (n) { (n._listeners[type] || []).forEach(fn => fn(ev)); n = n.parentNode; }
		return ev;
	}
	_matches(sel) {
		const m = sel.match(/^([a-zA-Z0-9]+)?(\.[a-zA-Z0-9_-]+)?((?:\[[^\]]+\])*)$/);
		if (!m) { return false; }
		if (m[1] && this.tagName !== m[1].toUpperCase()) { return false; }
		if (m[2] && !(this.attributes['class'] || '').split(/\s+/).includes(m[2].slice(1))) { return false; }
		const attrs = (m[3] || '').match(/\[[^\]]+\]/g) || [];
		for (const a of attrs) {
			const inner = a.slice(1, -1);
			const eq = inner.indexOf('=');
			if (eq === -1) {
				if (!(inner in this.attributes)) { return false; }
			} else {
				const k = inner.slice(0, eq).trim();
				const v = inner.slice(eq + 1).trim().replace(/^['"]|['"]$/g, '');
				const actual = k === 'value' ? this.value : this.getAttribute(k);
				if (actual !== v) { return false; }
			}
		}
		return true;
	}
	querySelectorAll(sel) {
		let base = sel;
		let wantChecked = false;
		if (sel.endsWith(':checked')) { base = sel.slice(0, -8); wantChecked = true; }
		const out = [];
		const walk = n => { n.children.forEach(c => {
			if (c._matches(base) && (!wantChecked || c.checked)) { out.push(c); }
			walk(c);
		}); };
		walk(this);
		return out;
	}
	querySelector(sel) { return this.querySelectorAll(sel)[0] || null; }
	closest(sel) { let n = this; while (n) { if (n._matches(sel)) { return n; } n = n.parentNode; } return null; }
}

class FakeFormData {
	constructor(form) {
		this._map = new Map();
		const walk = n => { n.children.forEach(c => {
			const name = c.getAttribute && c.getAttribute('name');
			if (name && !c.disabled) {
				const type = c.getAttribute('type') || 'text';
				if (type === 'checkbox' || type === 'radio') {
					if (c.checked) { this._map.set(name, c.value || 'on'); }
				} else {
					this._map.set(name, c.value);
				}
			}
			walk(c);
		}); };
		walk(form);
	}
	get(k) { return this._map.has(k) ? this._map.get(k) : null; }
	forEach(fn) { this._map.forEach((v, k) => fn(v, k)); }
}

function el(tag, attrs, parent) {
	const n = new FakeNode(tag);
	for (const k in (attrs || {})) {
		if (k === 'type' || k === 'name' || k === 'value' || k === 'max' || k === 'min' || k.startsWith('data-') || k.startsWith('aria-')) {
			n.setAttribute(k, attrs[k]);
		} else {
			n[k] = attrs[k];
		}
	}
	if (parent) { parent.appendChild(n); }
	return n;
}

function buildCheckout(opts) {
	opts = opts || {};
	const byId = {};
	const reg = n => { const w = c => { if (c.id) { byId[c.id] = c; } c.children.forEach(w); }; w(n); };
	const doc = new FakeNode('document');
	const form = el('form', { id: 'bck-checkout' }, doc);
	form.setAttribute('data-bck-offering-id', '7');
	form.setAttribute('data-bck-approval', '0');
	form.setAttribute('data-bck-pass', opts.pass ? '1' : '0');
	form.setAttribute('data-bck-unit', '2500');
	form.setAttribute('data-bck-currency', 'EUR');

	let occ1 = null;
	if (!opts.pass) {
		occ1 = el('input', { type: 'radio', name: 'occ', checked: true }, form);
		occ1.setAttribute('value', 'event:11');
		occ1.setAttribute('data-available', '3');
		const occ2 = el('input', { type: 'radio', name: 'occ' }, form);
		occ2.setAttribute('value', 'event:12');
		occ2.setAttribute('data-available', '0');
		occ2.disabled = true;
	}
	el('input', { id: 'bck-qty', type: 'number', name: 'qty', value: '1' }, form);
	el('input', { type: 'text', name: 'buyer_name' }, form);
	el('input', { type: 'email', name: 'buyer_email' }, form);
	const consent = el('input', { type: 'checkbox', name: 'consent', checked: true }, form);
	consent.setAttribute('value', '1');
	el('input', { id: 'bck-promo', type: 'text', name: 'promo' }, form);
	el('input', { type: 'text', name: 'hp_website' }, form);
	el('p', { id: 'bck-promo-hint', 'class': 'bck-field__hint' }, doc);
	el('button', { id: 'bck-promo-apply', type: 'button' }, doc);
	el('div', { id: 'bck-total' }, doc);
	const submitBtn = el('button', { id: 'bck-pay-btn', type: 'submit' }, doc);
	['lines', 'qty', 'buyer_name', 'buyer_email', 'consent', 'promo', 'addons', 'price_cat_id']
		.forEach(k => el('p', { id: 'bck-' + k + '-error', 'class': 'bck-field-error', hidden: true }, form));
	reg(doc);
	return { doc, byId, form, occ1, submitBtn };
}

function makeCtx(opts) {
	const built = buildCheckout(opts);
	const fetches = [];
	const toasts = { info: [], error: [] };
	const loc = { href: '' };
	const tFn = (s, vars) => {
		let out = s, i = 0;
		if (Array.isArray(vars)) { out = out.replace(/%s/g, () => String(vars[i++])); }
		return out;
	};
	const BCK = {
		appBase: () => '/apps/bookingcheck',
		t: tFn, tf: tFn,
		toast: { info: m => toasts.info.push(m), error: m => toasts.error.push(m) },
		el: (tag, a) => el(tag, a || {}),
	};
	const ctx = {
		console, Intl, URLSearchParams, Math, Date, JSON, Error, String, Number,
		Promise, Object, Array, parseInt, parseFloat, isFinite, encodeURIComponent,
		setTimeout, clearTimeout,
		FormData: FakeFormData,
		crypto: { randomUUID: () => 'uuid-1' },
		document: {
			documentElement: { lang: 'en' },
			getElementById: id => built.byId[id] || null,
			createElement: t => new FakeNode(t),
			querySelectorAll: sel => built.doc.querySelectorAll(sel),
			querySelector: sel => built.doc.querySelector(sel),
		},
		fetch: (url, o) => {
			fetches.push({ url, opts: o });
			const next = ctx.__fetchQueue.shift();
			const body = next === undefined ? {} : next;
			return Promise.resolve({ json: () => Promise.resolve(body) });
		},
		__fetchQueue: [],
		__fetches: fetches,
		__toasts: toasts,
	};
	ctx.window = { BCK, crypto: ctx.crypto, location: loc };
	ctx.BCK = BCK;
	ctx.__dom = built;
	vm.createContext(ctx);
	vm.runInContext(SRC, ctx);
	return ctx;
}

const flush = () => new Promise(r => setTimeout(r, 0));
const flushN = async n => { for (let i = 0; i < n; i++) { await flush(); } };

async function run() {
	/* ---- total + availability clamp ---- */
	const ctx = makeCtx();
	const dom = ctx.__dom;
	assert(dom.byId['bck-total'].textContent === 'EUR 25,00', 'initial total renders unit price');
	const qty = dom.byId['bck-qty'];
	assert(qty.max === '3', 'qty.max clamped to selected occurrence availability');
	qty.value = '5';
	qty.dispatch('input');
	await flush();
	assert(dom.byId['bck-total'].textContent === 'EUR 125,00', 'total follows qty before clamp event');
	// switching occurrence re-clamps an over-capacity qty
	dom.occ1.checked = false;
	const occ2 = dom.form.querySelectorAll('[name="occ"]')[1];
	// second radio is sold-out in the base fixture — use a fresh form with an
	// available smaller pool instead
	const ctx2 = makeCtx();
	const dom2 = ctx2.__dom;
	const qty2 = dom2.byId['bck-qty'];
	qty2.value = '3'; // at capacity
	const occ3 = new FakeNode('input');
	occ3.setAttribute('type', 'radio'); occ3.setAttribute('name', 'occ');
	occ3.setAttribute('value', 'event:13'); occ3.setAttribute('data-available', '1');
	dom2.form.appendChild(occ3);
	dom2.occ1.checked = false;
	occ3.checked = true;
	occ3.dispatch('change');
	await flush();
	assert(qty2.value === '1', 'qty auto-clamped down to new occurrence availability');
	assert(qty2.max === '1', 'qty.max follows the new occurrence');
	assert(dom2.byId['bck-total'].textContent === 'EUR 25,00', 'total re-renders after clamp');

	/* ---- promo percent reflects in the live total ---- */
	qty.value = '1'; // back to the base state after the clamp exercises
	const promo = dom.byId['bck-promo'];
	promo.value = 'SAVE10';
	ctx.__fetchQueue.push({ success: true, data: { valid: true, kind: 'percent', percent_bps: 1000 } });
	dom.byId['bck-promo-apply'].dispatch('click');
	await flushN(3);
	assert(dom.byId['bck-total'].textContent === 'EUR 22,50', 'percent promo discounts the live total');
	assert(/10%/.test(dom.byId['bck-promo-hint'].textContent), 'promo hint shows the discount');
	// invalid code → honest hint, total untouched
	promo.value = 'BAD';
	ctx.__fetchQueue.push({ success: true, data: { valid: false } });
	dom.byId['bck-promo-apply'].dispatch('click');
	await flushN(3);
	assert(dom.byId['bck-promo-hint'].textContent === 'This code is not valid.', 'invalid promo hint');
	// editing the code drops the stale discount
	promo.value = 'SAVE10 ';
	ctx.__fetchQueue.push({ success: true, data: { valid: true, kind: 'percent', percent_bps: 1000 } });
	dom.byId['bck-promo-apply'].dispatch('click');
	await flushN(3);
	promo.value = 'S';
	promo.dispatch('input');
	assert(dom.byId['bck-total'].textContent === 'EUR 25,00', 'editing code resets the displayed discount');

	/* ---- pre-submit validation: no request for obviously-bad input ---- */
	const ctx3 = makeCtx();
	const dom3 = ctx3.__dom;
	dom3.form.querySelector('[name="consent"]').checked = true;
	dom3.form.dispatch('submit');
	await flushN(3);
	assert(ctx3.__fetches.length === 0, 'empty name+email: submit blocked without a request');
	assert(dom3.byId['bck-buyer_name-error'].hidden === false, 'name error shown');
	assert(dom3.byId['bck-buyer_email-error'].hidden === false, 'email error shown');
	const nameIn = dom3.form.querySelector('[name="buyer_name"]');
	assert(nameIn.getAttribute('aria-invalid') === 'true', 'name flagged aria-invalid');
	assert(nameIn._focused >= 1, 'first invalid control receives focus');
	// bad email still blocks
	nameIn.value = 'Ada';
	dom3.form.querySelector('[name="buyer_email"]').value = 'not-an-email';
	dom3.form.dispatch('submit');
	await flushN(3);
	assert(ctx3.__fetches.length === 0, 'malformed email: submit still blocked');
	// consent unchecked blocks too
	dom3.form.querySelector('[name="buyer_email"]').value = 'ada@example.com';
	dom3.form.querySelector('[name="consent"]').checked = false;
	dom3.form.dispatch('submit');
	await flushN(3);
	assert(ctx3.__fetches.length === 0, 'unchecked consent: submit blocked');
	assert(dom3.byId['bck-consent-error'].hidden === false, 'consent error shown');

	/* ---- happy submit: payload shape + done handoff + aria-busy ---- */
	dom3.form.querySelector('[name="consent"]').checked = true;
	ctx3.__fetchQueue.push({ success: true, data: { action: 'done', manage_url: '/apps/bookingcheck/pub/m/abc' } });
	dom3.form.dispatch('submit');
	await flushN(4);
	const post = ctx3.__fetches.find(f => /\/pub\/api\/orders$/.test(f.url));
	assert(!!post, 'valid form posts to /pub/api/orders');
	const body = JSON.parse(post.opts.body);
	assert(body.offering_id === 7, 'payload: offering_id');
	assert(body.buyer_name === 'Ada' && body.buyer_email === 'ada@example.com', 'payload: buyer fields');
	assert(body.lines.length === 1 && body.lines[0].target_type === 'event' && body.lines[0].target_id === 11,
		'payload: occurrence line');
	assert(body.lines[0].seats === 1, 'payload: seats');
	assert(body.consent === '1', 'payload: consent');
	assert(body.idem_token === 'uuid1', 'payload: idempotency token');
	assert(ctx3.window.location.href === '/apps/bookingcheck/pub/m/abc', 'done action navigates to manage_url');

	/* ---- server field errors → inline slots, button freed, busy cleared ---- */
	const ctx4 = makeCtx();
	const dom4 = ctx4.__dom;
	dom4.form.querySelector('[name="buyer_name"]').value = 'Ada';
	dom4.form.querySelector('[name="buyer_email"]').value = 'ada@example.com';
	ctx4.__fetchQueue.push({ success: false, fields: { buyer_email: 'Taken.' } });
	dom4.form.dispatch('submit');
	await flushN(4);
	assert(dom4.byId['bck-buyer_email-error'].hidden === false, 'server field error rendered inline');
	assert(dom4.byId['bck-buyer_email-error'].textContent === 'Taken.', 'server error message text');
	assert(dom4.submitBtn.disabled === false, 'button re-enabled after server error');
	assert(dom4.submitBtn.getAttribute('aria-busy') === null, 'aria-busy cleared after server error');

	/* ---- replay → informational toast, button freed ---- */
	const ctx5 = makeCtx();
	const dom5 = ctx5.__dom;
	dom5.form.querySelector('[name="buyer_name"]').value = 'Ada';
	dom5.form.querySelector('[name="buyer_email"]').value = 'ada@example.com';
	ctx5.__fetchQueue.push({ success: true, data: { action: 'replay' } });
	dom5.form.dispatch('submit');
	await flushN(4);
	assert(ctx5.__toasts.info.length === 1, 'replay informs the buyer instead of double-holding');
	assert(dom5.submitBtn.disabled === false, 'replay re-enables the button');

	/* ---- network failure → error toast, button freed ---- */
	const ctx6 = makeCtx();
	const dom6 = ctx6.__dom;
	dom6.form.querySelector('[name="buyer_name"]').value = 'Ada';
	dom6.form.querySelector('[name="buyer_email"]').value = 'ada@example.com';
	// no queued response → fetch stub resolves {} → falls to message path
	dom6.form.dispatch('submit');
	await flushN(4);
	assert(dom6.submitBtn.disabled === false, 'empty response frees the button');

	/* ---- pass offering: pass_qty posted, no lines ---- */
	const ctx7 = makeCtx({ pass: true });
	const dom7 = ctx7.__dom;
	dom7.form.querySelector('[name="buyer_name"]').value = 'Ada';
	dom7.form.querySelector('[name="buyer_email"]').value = 'ada@example.com';
	dom7.byId['bck-qty'].value = '2';
	ctx7.__fetchQueue.push({ success: true, data: { action: 'done', manage_url: '/m/p' } });
	dom7.form.dispatch('submit');
	await flushN(4);
	const pbody = JSON.parse(ctx7.__fetches.find(f => /\/pub\/api\/orders$/.test(f.url)).opts.body);
	assert(pbody.pass_qty === 2, 'pass: pass_qty posted');
	assert(pbody.lines.length === 0, 'pass: no occurrence lines posted');
}

run().then(() => {
	if (failures === 0) {
		process.stdout.write('bck-checkout.test.cjs: all assertions passed\n');
	} else {
		process.stderr.write(failures + ' assertion(s) failed\n');
		process.exit(1);
	}
}).catch(e => {
	process.stderr.write('harness error: ' + e.stack + '\n');
	process.exit(1);
});

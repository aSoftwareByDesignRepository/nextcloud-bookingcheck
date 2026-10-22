#!/usr/bin/env node
/**
 * Behavioural tests for js/sdk/bck-embed.js (NH-05 WebComponent SDK).
 *
 * Runs the real file inside a vm context with a minimal DOM stub —
 * covers load/render, sold-out, resource re-render on occurrence change,
 * submit handoff (pay/approval), error fields, honeypot, tos gate and
 * the static no-innerHTML guarantee.
 *
 * Run: node tests/js/bck-embed.test.cjs
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..', '..');
const SRC = fs.readFileSync(path.join(ROOT, 'js', 'sdk', 'bck-embed.js'), 'utf8');

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
		// DOMStringMap semantics: assigned values stringify
		this.dataset = new Proxy({}, {
			set: (t, k, v) => { t[k] = String(v); return true; },
		});
		this.style = { setProperty: (k, v) => { this._styleProps = this._styleProps || {}; this._styleProps[k] = v; } };
		this._text = '';
		this._listeners = {};
		this.disabled = false;
		this.checked = false;
		this.id = '';
		this._shadow = null;
	}
	get options() { return this.children.filter(c => c.tagName === 'OPTION'); }
	get selectedIndex() {
		if (this._selIdx !== undefined) { return this._selIdx; }
		const i = this.options.findIndex(o => !o.disabled);
		return i === -1 ? 0 : i;
	}
	selectOption(i) { this._selIdx = i; }
	get value() {
		if (this._value !== undefined) { return this._value; }
		if (this.tagName === 'SELECT') {
			const o = this.options[this.selectedIndex];
			return o ? (o.getAttribute('value') || '') : '';
		}
		return this.getAttribute('value') || '';
	}
	set value(v) { this._value = String(v); }
	get textContent() {
		return this._text + this.children.map(c => c.textContent).join('');
	}
	set textContent(v) { this.children = []; this._text = String(v); }
	getAttribute(k) { return k in this.attributes ? this.attributes[k] : null; }
	setAttribute(k, v) { this.attributes[k] = String(v); }
	removeAttribute(k) { delete this.attributes[k]; }
	appendChild(n) { n.parentNode = this; this.children.push(n); return n; }
	insertBefore(n, ref) {
		const i = ref ? this.children.indexOf(ref) : -1;
		n.parentNode = this;
		if (i === -1) { this.children.push(n); } else { this.children.splice(i, 0, n); }
		return n;
	}
	remove() {
		if (this.parentNode) {
			const i = this.parentNode.children.indexOf(this);
			if (i !== -1) { this.parentNode.children.splice(i, 1); }
			this.parentNode = null;
		}
	}
	get nextSibling() {
		if (!this.parentNode) { return null; }
		const i = this.parentNode.children.indexOf(this);
		return this.parentNode.children[i + 1] || null;
	}
	attachShadow() { this._shadow = new FakeNode('#shadow'); return this._shadow; }
	addEventListener(type, fn) { (this._listeners[type] = this._listeners[type] || []).push(fn); }
	dispatch(type) {
		if (type === 'click' && this.disabled) { return; }
		(this._listeners[type] || []).forEach(fn => fn({ target: this }));
	}
	/** narrow selector engine: 'tag', '.cls', '[attr]', 'tag[attr]', 'tag[attr][attr]' */
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
		const out = [];
		const walk = n => { n.children.forEach(c => { if (c._matches(sel)) { out.push(c); } walk(c); }); };
		walk(this);
		return out;
	}
	closest(sel) {
		let n = this;
		while (n) { if (n._matches(sel)) { return n; } n = n.parentNode; }
		return null;
	}
}

function makeCtx(overrides) {
	const fetches = [];
	const ctx = {
		console,
		Intl,
		URLSearchParams,
		Math,
		Date,
		JSON,
		Error,
		String,
		Number,
		Promise,
		encodeURIComponent,
		setTimeout, clearTimeout,
		crypto: { randomUUID: () => 'uuid-1' },
		navigator: { language: 'en' },
		fetch: (url, opts) => {
			fetches.push({ url, opts });
			const next = ctx.__fetchQueue.shift();
			if (next === undefined) { return Promise.reject(new Error('no queued response')); }
			if (next instanceof Error) { return Promise.reject(next); }
			return Promise.resolve({
				status: next.status || 200,
				json: () => Promise.resolve(next.body),
			});
		},
		__fetchQueue: [],
		__fetches: fetches,
	};
	ctx.window = { top: { location: { href: '' } } };
	ctx.HTMLElement = FakeNode;
	ctx.customElements = {
		defined: {},
		define(name, cls) { this.defined[name] = cls; },
	};
	ctx.document = {
		currentScript: { src: (overrides && overrides.scriptSrc !== undefined)
			? overrides.scriptSrc
			: 'https://nc.example.com/apps/bookingcheck/pub/sdk.js' },
		createElement: tag => new FakeNode(tag),
	};
	Object.assign(ctx, overrides || {});
	vm.createContext(ctx);
	vm.runInContext(SRC, ctx);
	return ctx;
}

function makeEl(ctx, attrs) {
	const Cls = ctx.customElements.defined['bck-booking'];
	const el = new Cls();
	for (const k in (attrs || {})) { el.setAttribute(k, attrs[k]); }
	el.connectedCallback();
	return el;
}

function flush() { return new Promise(r => setTimeout(r, 0)); }

const PAYLOAD = {
	success: true,
	data: {
		offering: { id: 7, title: 'Yoga <img src=x>', descr: 'Stretch', price_cents: 2500, currency: 'EUR', type: 'event' },
		occurrences: [
			{ id: 11, target_type: 'event', starts_at: '2026-07-01', label: 'Jul 1', available: 5,
				resources: [{ id: 91, name: 'Mat A', available: 3 }, { id: 92, name: 'Mat B', available: 0 }] },
			{ id: 12, target_type: 'event', starts_at: '2026-07-08', label: 'Jul 8', available: 3 },
			{ id: 13, target_type: 'event', starts_at: '2026-07-15', label: 'Jul 15', available: 0 },
		],
		priceCats: [{ id: 5, name: 'Student', priceCents: 1800 }],
		addons: [{ id: 3, name: 'Tea', priceCents: 300 }],
		tosRequired: true,
		tosText: 'be nice',
		invoicePay: true,
		approvalRequired: false,
		depositBp: 0,
	},
};

async function mountedEl(ctx, attrs, payload) {
	ctx.__fetchQueue.push({ status: 200, body: payload || PAYLOAD });
	const el = makeEl(ctx, Object.assign({ slug: 'yoga' }, attrs || {}));
	await flush();
	return el;
}

async function run() {
	const ctx = makeCtx();

	// registration
	assert(!!ctx.customElements.defined['bck-booking'], 'custom element registered');

	// static guarantee: no innerHTML anywhere in the SDK source
	assert(!/\.innerHTML\s*[=+]|insertAdjacentHTML\s*\(|document\.write\s*\(/.test(SRC), 'SDK uses no innerHTML sinks');

	// successful load renders title + occurrence select
	let el = await mountedEl(ctx);
	assert(el.box.querySelectorAll('h2').length === 1, 'renders title h2');
	assert(el.box.querySelectorAll('select').length >= 2, 'renders occurrence + pricecat selects');
	assert(el.box.querySelectorAll('button').length === 1, 'renders submit button');
	const titleText = el.box.querySelectorAll('h2')[0].textContent;
	assert(titleText === 'Yoga <img src=x>', 'title landed via textContent (raw, not parsed)');
	assert(el.occSel.options[2].disabled === true, 'sold-out occurrence option disabled');
	assert(el.occSel.selectedIndex === 0, 'first available occurrence preselected');

	// resources rendered for first occurrence; change re-renders
	assert(el.resSel, 'resource select rendered');
	assert(el.resSel.options.length === 2, 'resource options populated');
	assert(el.resSel.options[1].disabled === true, 'zero-available resource disabled');
	el.occSel.selectOption(1);
	el.occSel.dispatch('change');
	assert(el.resSel === null, 'resource select removed on occurrence without resources');
	el.occSel.selectOption(0);
	el.occSel.dispatch('change');
	assert(el.resSel && el.resSel.options.length === 2, 'resource select restored');

	// sold-out payload → no button, .bad notice
	el = await mountedEl(ctx, { slug: 'full' }, {
		success: true, data: { ...PAYLOAD.data,
			occurrences: [{ id: 1, target_type: 'event', label: 'x', available: 0 }] },
	});
	assert(el.box.querySelectorAll('button').length === 0, 'sold-out: no submit button');
	assert(el.box.querySelectorAll('.bad').length === 1, 'sold-out: notice rendered');

	// tos unchecked → error shown, NO fetch to orders
	el = await mountedEl(ctx);
	const fetchCountBefore = ctx.__fetches.length;
	el.btn.dispatch('click');
	await flush();
	assert(ctx.__fetches.length === fetchCountBefore, 'tos unchecked: no order POST');
	assert(el.status.querySelectorAll('.bad').length === 1, 'tos unchecked: error shown');
	assert(el.tosCb.getAttribute('aria-invalid') === 'true', 'tos field aria-invalid');

	// happy submit → pay action → top.location handoff
	el.tosCb.checked = true;
	el.nameInput.value = 'Ada Buyer';
	el.emailInput.value = 'ada@example.com';
	el.catSel.selectOption(1); // Student 5
	ctx.__fetchQueue.push({ status: 200, body: { success: true, data: { action: 'pay', checkout_url: 'https://pay.example/checkout/xyz' } } });
	el.btn.dispatch('click');
	await flush(); await flush(); await flush();
	const post = ctx.__fetches.find(f => /\/pub\/api\/orders$/.test(f.url));
	assert(!!post, 'order POST issued');
	const params = new URLSearchParams(post.opts.body);
	assert(params.get('offering_id') === '7', 'offering_id sent');
	assert(params.get('buyer_name') === 'Ada Buyer', 'buyer_name sent');
	assert(params.get('buyer_email') === 'ada@example.com', 'buyer_email sent');
	assert(params.get('idem_token') === 'uuid-1', 'idempotency token sent');
	assert(params.has('hp_website'), 'honeypot field present');
	assert(params.get('consent') === '1', 'tos consent sent');
	const lines = JSON.parse(params.get('lines'));
	assert(lines[0].target_type === 'event' && lines[0].target_id === 11, 'line carries selected occurrence');
	assert(lines[0].resource_id === 91, 'line carries resource choice');
	assert(lines[0].price_cat_id === 5, 'line carries price category');
	assert(ctx.window.top.location.href === 'https://pay.example/checkout/xyz', 'pay: _top checkout handoff');
	assert(el.btn.disabled === true, 'pay: button stays disabled after handoff');

	// double-submit prevention: a second click is a no-op in a real browser
	// (disabled button swallows click) — stub emulates via dispatch guard
	const postsBefore = ctx.__fetches.filter(f => /\/pub\/api\/orders$/.test(f.url)).length;
	el.btn.dispatch('click');
	assert(ctx.__fetches.filter(f => /\/pub\/api\/orders$/.test(f.url)).length === postsBefore,
		'disabled button cannot be re-clicked');

	// validation error response → field errors, no navigation
	const el2 = await mountedEl(ctx, { slug: 'yoga2' });
	el2.tosCb.checked = true;
	el2.nameInput.value = 'Ada';
	el2.emailInput.value = 'bad';
	ctx.__fetchQueue.push({ status: 400, body: { success: false, message: 'Check fields.', fields: { buyer_email: 'Invalid.' } } });
	const locBefore2 = ctx.window.top.location.href;
	el2.btn.dispatch('click');
	await flush(); await flush(); await flush();
	assert(el2.status.querySelectorAll('.bad').length === 1, 'server error banner shown');
	assert(el2.emailInput.getAttribute('aria-invalid') === 'true', 'email flagged aria-invalid');
	assert(el2.btn.disabled === false, 'button re-enabled after failed submit');
	assert(ctx.window.top.location.href === locBefore2, 'error: no navigation');

	// approval action → status text, button stays disabled, no navigation
	const ctx3 = makeCtx();
	const el3 = await mountedEl(ctx3, { slug: 'yoga3' });
	el3.tosCb.checked = true;
	el3.nameInput.value = 'Ada'; el3.emailInput.value = 'a@b.c';
	ctx3.__fetchQueue.push({ status: 200, body: { success: true, data: { action: 'approval', manage_url: 'https://nc/m/abc' } } });
	el3.btn.dispatch('click');
	await flush(); await flush(); await flush();
	assert(el3.status.querySelectorAll('.ok').length === 1, 'approval: ok status');
	assert(el3.btn.disabled === true, 'approval: button stays disabled');
	assert(el3.status.querySelectorAll('a[href]').length === 1, 'approval: manage link rendered');

	// fetch failure → unavailable message, no crash
	const ctx4 = makeCtx();
	ctx4.__fetchQueue.push(new Error('net'));
	const el4 = makeEl(ctx4, { slug: 'x' });
	await flush();
	assert(el4.box.querySelectorAll('.muted').length === 1, 'network failure: unavailable shown');

	// pass offering → pass_qty posted, no occurrence controls
	const ctx5 = makeCtx();
	const passEl = await mountedEl(ctx5, { slug: 'gym' }, {
		success: true, data: { ...PAYLOAD.data,
			offering: { ...PAYLOAD.data.offering, type: 'pass' },
			occurrences: [], priceCats: [], addons: [] },
	});
	assert(!passEl.occSel, 'pass: no occurrence select');
	passEl.tosCb.checked = true;
	passEl.nameInput.value = 'Ada'; passEl.emailInput.value = 'a@b.c';
	passEl.passQty.value = '3';
	ctx5.__fetchQueue.push({ status: 200, body: { success: true, data: { action: 'done', manage_url: 'https://nc/m/p' } } });
	passEl.btn.dispatch('click');
	await flush(); await flush(); await flush();
	const ppost = ctx5.__fetches.find(f => /\/pub\/api\/orders$/.test(f.url));
	assert(new URLSearchParams(ppost.opts.body).get('pass_qty') === '3', 'pass: pass_qty posted');
	assert(!new URLSearchParams(ppost.opts.body).has('lines'), 'pass: no lines posted');
	assert(passEl.status.querySelectorAll('.ok').length === 1, 'pass: done status');

	// accent attr: non-hex rejected, mid-contrast rejected (no compliant
	// ink exists), light accent applied with dark ink + default text accent
	const ctx6 = makeCtx();
	const el6 = await mountedEl(ctx6, { accent: 'javascript:alert(1)' });
	assert(!(el6._styleProps && '--bck-accent' in el6._styleProps), 'non-hex accent rejected');
	const el8 = await mountedEl(ctx6, { accent: '#7d7d7d' });
	assert(!(el8._styleProps && '--bck-accent' in el8._styleProps),
		'mid-contrast accent rejected — neither white nor dark ink reaches 4.5:1');
	const el7 = await mountedEl(ctx6, { accent: '#ff8800' });
	assert(el7._styleProps['--bck-accent'] === '#ff8800', 'hex accent applied');
	assert(el7._styleProps['--bck-accent-ink'] === '#1b1b1b', 'light accent picks dark ink (6.4:1)');
	assert(!('--bck-accent-text' in el7._styleProps), 'light accent keeps AA default text accent');
	const el9 = await mountedEl(ctx6, { accent: '#004a80' });
	assert(el9._styleProps['--bck-accent-ink'] === '#ffffff', 'dark accent keeps white ink');
	assert(el9._styleProps['--bck-accent-text'] === '#004a80', 'dark accent usable as text accent');
}

run().then(() => {
	if (failures === 0) {
		process.stdout.write('bck-embed.test.cjs: all assertions passed\n');
	} else {
		process.stderr.write(failures + ' assertion(s) failed\n');
		process.exit(1);
	}
}).catch(e => {
	process.stderr.write('harness error: ' + e.stack + '\n');
	process.exit(1);
});

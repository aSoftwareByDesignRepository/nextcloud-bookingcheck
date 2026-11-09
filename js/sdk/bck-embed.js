/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * NH-05 — BookingCheck SDK embed: <bck-booking slug="…" base="…" lang="de">
 *
 * Same-origin alternative to the iframe embed: a WebComponent that runs
 * on the merchant page, fetches the offering card as JSON and posts the
 * order to the anonymous checkout API. Cross-origin reads only work
 * when the page origin is in the offering's embed_domains allowlist
 * (server reflects ACAO — see EmbedCors). Everything else fails closed:
 * the fetch rejects, the widget shows its error state.
 *
 * Security notes for reviewers:
 * - All server data lands via textContent — no innerHTML anywhere.
 * - POST is application/x-www-form-urlencoded (a "simple request"):
 *   no preflight, ACAO on the response is the only CORS need.
 * - Same honeypot + idempotency fields as the on-site checkout.
 */
(function () {
	'use strict';

	// derive the API base from this script's own URL when no `base`
	// attribute is given: <base>/pub/sdk.js → <base>
	var SCRIPT_SRC = (document.currentScript && document.currentScript.src) || '';
	var SCRIPT_BASE = SCRIPT_SRC.replace(/\/pub\/sdk\.js(\?.*)?$/, '');

	var STR = {
		en: {
			loading: 'Loading booking…', unavailable: 'This booking is currently unavailable.',
			date: 'Date', seats: 'Seats', seatsPh: '1', category: 'Ticket type',
			addons: 'Extras', name: 'Name', email: 'Email',
			company: 'Company (optional)', country: 'Billing country (optional, e.g. DE)',
			vatId: 'VAT ID (optional)',
			tosLabel: 'I accept the booking terms shown by the seller.',
			payInvoice: 'Pay by invoice', payNow: 'Pay now', book: 'Book',
			booking: 'Booking…', done: 'Booking confirmed!',
			approval: 'Booking request sent — the seller will confirm shortly.',
			manage: 'Open your booking', error: 'Something went wrong — please try again.',
			soldOut: 'Sold out', passQty: 'Quantity',
			optionalFields: 'Show company / VAT fields',
			deposit: 'deposit', paymentMethod: 'Payment method'
		},
		de: {
			loading: 'Buchung wird geladen…', unavailable: 'Diese Buchung ist derzeit nicht verfügbar.',
			date: 'Termin', seats: 'Plätze', seatsPh: '1', category: 'Ticketart',
			addons: 'Extras', name: 'Name', email: 'E-Mail',
			company: 'Firma (optional)', country: 'Rechnungsland (optional, z. B. DE)',
			vatId: 'USt-IdNr. (optional)',
			tosLabel: 'Ich akzeptiere die vom Anbieter gezeigten Buchungsbedingungen.',
			payInvoice: 'Auf Rechnung zahlen', payNow: 'Jetzt zahlen', book: 'Buchen',
			booking: 'Bucht…', done: 'Buchung bestätigt!',
			approval: 'Buchungsanfrage gesendet — der Anbieter bestätigt in Kürze.',
			manage: 'Zur Buchung', error: 'Etwas ist schiefgegangen — bitte erneut versuchen.',
			soldOut: 'Ausverkauft', passQty: 'Anzahl',
			optionalFields: 'Firmen-/USt-Felder anzeigen',
			deposit: 'Anzahlung', paymentMethod: 'Zahlungsart'
		}
	};

	var CSS = [
		// default accent is WCAG-AA: white on #0069a8 = 5.9:1, and #0069a8
		// on white passes as link/focus text (≥4.5:1). Seller accents go
		// through the luminance gate in connectedCallback.
		':host{display:block;font-family:system-ui,-apple-system,sans-serif;color:#1b1b1b;',
		'--bck-accent:#0069a8;--bck-accent-ink:#ffffff;--bck-accent-text:#0069a8;}',
		'.bck{border:1px solid #d8d8d8;border-radius:12px;padding:20px;max-width:26rem;background:#fff;}',
		'h2{margin:0 0 4px;font-size:1.15rem;line-height:1.3;}',
		'.desc{margin:0 0 14px;color:#4a4a4a;font-size:.92rem;}',
		'label{display:block;font-weight:600;font-size:.9rem;margin:12px 0 4px;}',
		'input,select{box-sizing:border-box;width:100%;min-height:44px;padding:8px 10px;font-size:1rem;',
		'border:1px solid #949494;border-radius:8px;background:#fff;color:#1b1b1b;}',
		'input:focus-visible,select:focus-visible,button:focus-visible,a:focus-visible{outline:3px solid var(--bck-accent-text);outline-offset:2px;}',
		'input[aria-invalid="true"],select[aria-invalid="true"]{border-color:#c42b1c;}',
		'.err{color:#c42b1c;font-size:.85rem;margin:4px 0 0;}',
		'.row{display:flex;align-items:center;gap:10px;margin:8px 0;font-size:.95rem;}',
		'.row input[type=checkbox],.row input[type=radio]{width:20px;height:20px;min-height:0;flex:0 0 auto;}',
		'.price{font-weight:700;font-size:1.05rem;margin:2px 0 0;}',
		'.muted{color:#5a5a5a;font-size:.85rem;}',
		'button{width:100%;min-height:48px;margin-top:16px;font-size:1rem;font-weight:700;',
		'border:0;border-radius:8px;background:var(--bck-accent);color:var(--bck-accent-ink);cursor:pointer;}',
		'button[disabled]{opacity:.6;cursor:default;}',
		'.status{margin-top:14px;font-size:.95rem;}',
		'.ok{background:#e6f5e6;border:1px solid #2d8a2d;border-radius:8px;padding:12px;}',
		'.bad{background:#fdecea;border:1px solid #c42b1c;border-radius:8px;padding:12px;}',
		'a{color:var(--bck-accent-text);}',
		'details{margin:12px 0 0;}summary{cursor:pointer;font-size:.9rem;font-weight:600;min-height:44px;display:flex;align-items:center;}',
		'.hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;}'
	].join('');

	/**
	 * WCAG accent gate. A seller accent is applied only when it can carry
	 * readable button ink (white or near-black at ≥4.5:1). Returns
	 * {fg, onWhite} or null for mid-tone colors where neither ink works.
	 */
	function chan(c) { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); }
	function lum(r, g, b) { return 0.2126 * chan(r) + 0.7152 * chan(g) + 0.0722 * chan(b); }
	function ratio(l1, l2) { return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05); }
	function accentInk(hex) {
		var h = hex.replace('#', '');
		if (!/^[0-9a-f]+$/i.test(h)) { return null; }
		if (h.length === 3) { h = h.split('').map(function (c) { return c + c; }).join(''); }
		if (h.length === 8) {
			if (h.slice(6, 8).toLowerCase() !== 'ff') { return null; } // alpha → rejected
			h = h.slice(0, 6);
		}
		if (h.length !== 6) { return null; }
		var n = parseInt(h, 16);
		var L = lum((n >> 16) & 255, (n >> 8) & 255, n & 255);
		var w = ratio(L, 1), d = ratio(L, lum(0x1b, 0x1b, 0x1b));
		if (w < 4.5 && d < 4.5) { return null; } // mid-tone: no compliant ink
		return { fg: w >= d ? '#ffffff' : '#1b1b1b', onWhite: w >= 4.5 };
	}

	function el(tag, attrs, text) {
		var n = document.createElement(tag);
		if (attrs) { for (var k in attrs) {
			if (k.slice(0, 2) === 'on' && typeof attrs[k] === 'function') { n.addEventListener(k.slice(2).toLowerCase(), attrs[k]); }
			else { n.setAttribute(k, attrs[k]); }
		} }
		if (text !== undefined && text !== null) { n.textContent = text; }
		return n;
	}
	function field(label, input) {
		var wrap = document.createElement('div');
		var id = 'bck-' + Math.random().toString(36).slice(2, 9);
		input.id = id;
		var l = el('label', { 'for': id }, label);
		wrap.appendChild(l); wrap.appendChild(input);
		return { wrap: wrap, input: input, id: id };
	}

	customElements.define('bck-booking', class extends HTMLElement {
			connectedCallback() {
			var lang = (this.getAttribute('lang') || navigator.language || 'en').slice(0, 2);
			this.lang = lang;
			// Clone: server-provided strings are merged per instance — mutating
			// the shared bundle would leak one widget's locale into the next.
			this.t = Object.assign({}, STR[lang] || STR.en);
			this.base = (this.getAttribute('base') || SCRIPT_BASE).replace(/\/+$/, '');
			this.slug = this.getAttribute('slug') || '';
			this.accent = this.getAttribute('accent');
			var root = this.attachShadow({ mode: 'open' });
			var style = document.createElement('style');
			style.textContent = CSS;
			root.appendChild(style);
			this.box = el('div', { 'class': 'bck' });
			root.appendChild(this.box);
			this.box.appendChild(el('p', { 'class': 'muted', 'aria-live': 'polite' }, this.t.loading));
			if (this.accent) {
				var ink = accentInk(this.accent);
				if (ink !== null) {
					this.style.setProperty('--bck-accent', this.accent);
					this.style.setProperty('--bck-accent-ink', ink.fg);
					if (ink.onWhite) {
						this.style.setProperty('--bck-accent-text', this.accent);
					}
					// else: keep the AA default text accent — a light seller
					// color must not silently break link/focus contrast
				}
			}
			this.load();
		}

		load() {
			var self = this;
			// Full locale (e.g. pt-BR), not the 2-char STR key — the server
			// normalizes and maps to its closest shipped catalog.
			var reqLocale = this.getAttribute('lang') || navigator.language || 'en';
			fetch(this.base + '/pub/api/o/' + encodeURIComponent(this.slug)
					+ '?locale=' + encodeURIComponent(reqLocale), { credentials: 'omit' })
				.then(function (r) { return r.json(); })
				.then(function (body) {
					if (!body || !body.success || !body.data || !body.data.offering) {
						throw new Error('unavailable');
					}
					// Server-provided strings (all shipped locales) override the
					// bundled en/de map; older servers simply omit `strings`
					// and the bundle keeps working as fallback.
					if (body.data.strings) {
						for (var k in body.data.strings) { self.t[k] = body.data.strings[k]; }
					}
					self.data = body.data;
					self.render();
				})
				.catch(function () {
					self.box.textContent = '';
					self.box.appendChild(el('p', { 'class': 'muted', 'aria-live': 'polite' }, self.t.unavailable));
				});
		}

		render() {
			var t = this.t, d = this.data, o = d.offering;
			var box = this.box;
			box.textContent = '';
			var fmt = new Intl.NumberFormat(this.getAttribute('lang') || undefined,
				{ style: 'currency', currency: o.currency || 'EUR' });
			this.fmt = fmt;

			box.appendChild(el('h2', {}, o.title));
			if (o.descr) { box.appendChild(el('p', { 'class': 'desc' }, o.descr)); }
			box.appendChild(el('p', { 'class': 'price' }, fmt.format(o.price_cents / 100)));
			if (d.depositBp > 0) {
				box.appendChild(el('p', { 'class': 'muted' },
					(d.depositBp / 100).toFixed(0) + '% ' + t.deposit));
			}

			this.lineInput = null;
			if (o.type === 'pass') {
				var pf = field(t.passQty, el('input', { type: 'number', min: '1', max: '50', value: '1', inputmode: 'numeric' }));
				box.appendChild(pf.wrap); this.passQty = pf.input;
			} else if (d.occurrences && d.occurrences.length) {
				var sel = el('select', {});
				var anyFree = false;
				d.occurrences.forEach(function (occ) {
					var opt = el('option', { value: occ.target_type + ':' + occ.id },
						(occ.label || occ.title || occ.starts_at || '') +
						(occ.available <= 0 ? ' — ' + t.soldOut : ''));
					opt.disabled = occ.available <= 0;
					if (occ.available > 0) { anyFree = true; }
					opt.dataset.tt = occ.target_type; opt.dataset.tid = occ.id;
					sel.appendChild(opt);
				});
				var of = field(t.date, sel); box.appendChild(of.wrap); this.occSel = sel;
				var self0 = this;
				sel.addEventListener('change', function () { self0.updateResources(); });

				if (d.priceCats && d.priceCats.length) {
					var pc = el('select', {});
					var any = el('option', { value: '' }, '—');
					pc.appendChild(any);
					d.priceCats.forEach(function (c) {
						pc.appendChild(el('option', { value: String(c.id) },
							c.name + ' · ' + fmt.format(c.priceCents / 100)));
					});
					var cf = field(t.category, pc); box.appendChild(cf.wrap); this.catSel = pc;
				}
				var sf = field(t.seats, el('input', { type: 'number', min: '1', max: '50', value: '1', inputmode: 'numeric', placeholder: t.seatsPh }));
				this.seatWrap = sf.wrap;
				box.appendChild(sf.wrap); this.seatInput = sf.input;

				this.resSel = null;
				this.updateResources();
				if (!anyFree) {
					this.status = el('div', { 'class': 'status', 'aria-live': 'polite' });
					box.appendChild(el('div', { 'class': 'bad' }, t.soldOut));
					box.appendChild(this.status);
					return;
				}
			} else if (o.type !== 'pass') {
				box.appendChild(el('div', { 'class': 'bad' }, t.unavailable));
				return;
			}
			if (d.addons && d.addons.length && o.type !== 'pass') {
				box.appendChild(el('label', {}, t.addons));
				this.addonBoxes = [];
				d.addons.forEach(function (a) {
					var cb = el('input', { type: 'checkbox', value: String(a.id) });
					var row = el('div', { 'class': 'row' });
					var id = 'bcka-' + a.id; cb.id = id;
					row.appendChild(cb);
					row.appendChild(el('label', { 'for': id, style: 'margin:0;font-weight:400;' },
						a.name + ' · ' + fmt.format(a.priceCents / 100)));
					box.appendChild(row);
				});
			}

			var nf = field(t.name, el('input', { type: 'text', autocomplete: 'name', required: '' }));
			var ef = field(t.email, el('input', { type: 'email', autocomplete: 'email', required: '' }));
			box.appendChild(nf.wrap); box.appendChild(ef.wrap);
			this.nameInput = nf.input; this.emailInput = ef.input;

			var det = el('details', {});
			det.appendChild(el('summary', {}, t.optionalFields));
			var bf = field(t.company, el('input', { type: 'text', autocomplete: 'organization' }));
			var cf2 = field(t.country, el('input', { type: 'text', maxlength: '2', autocomplete: 'country', style: 'text-transform:uppercase;' }));
			var vf = field(t.vatId, el('input', { type: 'text', autocomplete: 'off' }));
			det.appendChild(bf.wrap); det.appendChild(cf2.wrap); det.appendChild(vf.wrap);
			box.appendChild(det);
			this.companyInput = bf.input; this.countryInput = cf2.input; this.vatInput = vf.input;

			if (d.tosRequired) {
				var cb2 = el('input', { type: 'checkbox' });
				var row2 = el('div', { 'class': 'row' });
				var cid = 'bck-tos'; cb2.id = cid;
				row2.appendChild(cb2);
				row2.appendChild(el('label', { 'for': cid, style: 'margin:0;font-weight:400;' }, t.tosLabel));
				box.appendChild(row2);
				if (d.tosText) { box.appendChild(el('p', { 'class': 'muted' }, d.tosText)); }
				this.tosCb = cb2;
			}

			if (d.invoicePay) {
				var pm = el('div', {});
				pm.appendChild(el('label', {}, t.paymentMethod));
				var mk = function (v, ltxt, checked) {
					var r = el('input', { type: 'radio', name: 'bckpm', value: v });
					if (checked) { r.checked = true; }
					var row = el('div', { 'class': 'row' });
					var rid = 'bckpm-' + v; r.id = rid;
					row.appendChild(r); row.appendChild(el('label', { 'for': rid, style: 'margin:0;font-weight:400;' }, ltxt));
					pm.appendChild(row);
					return r;
				};
				this.pmPsp = mk('psp', t.payNow, true);
				this.pmInv = mk('invoice', t.payInvoice, false);
				box.appendChild(pm);
			}

			// honeypot — invisible to humans; bots that fill it get a silent 200
			var hp = el('input', { type: 'text', name: 'hp_website', 'class': 'hp', tabindex: '-1', autocomplete: 'off', 'aria-hidden': 'true' });
			box.appendChild(hp); this.hp = hp;

			this.btn = el('button', { type: 'button' }, d.approvalRequired ? t.book : t.payNow);
			var self = this;
			this.btn.addEventListener('click', function () { self.submit(); });
			box.appendChild(this.btn);
			this.status = el('div', { 'class': 'status', 'aria-live': 'polite' });
			box.appendChild(this.status);
		}

		updateResources() {
			// pooled slots: the buyer picks a resource inside the slot
			var occ = this.selectedOcc();
			var has = occ && occ.resources && occ.resources.length;
			if (!has) {
				if (this.resWrap) { this.resWrap.remove(); this.resWrap = null; this.resSel = null; }
				return;
			}
			if (!this.resSel) {
				var sel = el('select', {});
				var f = field('Resource', sel);
				this.resWrap = f.wrap;
				this.box.insertBefore(f.wrap, this.seatWrap || this.btn || null);
				this.resSel = sel;
			}
			var sel2 = this.resSel;
			sel2.textContent = '';
			occ.resources.forEach(function (r) {
				var opt = el('option', { value: String(r.id) },
					r.name + ' (' + r.available + ')');
				opt.disabled = r.available <= 0;
				sel2.appendChild(opt);
			});
		}

		selectedOcc() {
			if (!this.occSel || !this.data) { return null; }
			var idx = this.occSel.selectedIndex;
			var opt = this.occSel.options[idx];
			if (!opt) { return null; }
			var found = null;
			this.data.occurrences.forEach(function (o) {
				if (o.target_type === opt.dataset.tt && String(o.id) === opt.dataset.tid) { found = o; }
			});
			return found;
		}

		submit() {
			var t = this.t, d = this.data, o = d.offering, self = this;
			if (this.tosCb && !this.tosCb.checked) {
				this.showErr(t.error, { consent: t.tosLabel });
				return;
			}
			var fd = new URLSearchParams();
			fd.set('offering_id', String(o.id));
			fd.set('buyer_name', this.nameInput.value.trim());
			fd.set('buyer_email', this.emailInput.value.trim());
			fd.set('locale', (this.getAttribute('lang') || 'en').slice(0, 2));
			fd.set('idem_token', (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + '-' + Math.random()));
			fd.set('hp_website', this.hp.value);
			if (o.type === 'pass') {
				fd.set('pass_qty', String(Math.max(1, Math.floor(+(this.passQty ? this.passQty.value : '1') || 1))));
			} else {
				var occ = this.selectedOcc();
				if (!occ) { this.showErr(t.error, {}); return; }
				var seats = Math.max(1, Math.floor(+(this.seatInput ? this.seatInput.value : '1') || 1));
				var line = { target_type: occ.target_type, target_id: occ.id, seats: seats };
				if (this.catSel && this.catSel.value !== '') { line.price_cat_id = +this.catSel.value; }
				if (this.resSel && this.resSel.value !== '') { line.resource_id = +this.resSel.value; }
				fd.set('lines', JSON.stringify([line]));
				var addons = [];
				(this.box.querySelectorAll('input[type=checkbox][value]') || []).forEach(function (cb) {
					if (cb.checked) { addons.push({ addon_id: +cb.value, qty: 1 }); }
				});
				if (addons.length) { fd.set('addons', JSON.stringify(addons)); }
			}
			if (this.companyInput.value.trim()) { fd.set('buyer_company', this.companyInput.value.trim()); }
			if (this.countryInput.value.trim()) { fd.set('billing_country', this.countryInput.value.trim().toUpperCase()); }
			if (this.vatInput.value.trim()) { fd.set('vat_id', this.vatInput.value.trim()); }
			if (this.tosCb) { fd.set('consent', '1'); }
			if (this.pmInv && this.pmInv.checked) { fd.set('pay_method', 'invoice'); }

			this.btn.disabled = true;
			this.btn.textContent = t.booking;
			this.clearErrs();

			fetch(this.base + '/pub/api/orders', {
				method: 'POST',
				credentials: 'omit',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: fd.toString()
			}).then(function (r) { return r.json().then(function (b) { return { status: r.status, body: b }; }); })
				.then(function (res) {
					var b = res.body;
					if (!b) { throw new Error('bad'); }
					if (!b.success) {
						self.showErr(b.message || t.error, b.fields || {});
						return;
					}
					var act = b.data && b.data.action;
					if (act === 'pay' && b.data.checkout_url) {
						self.completed = true;
						window.top.location.href = b.data.checkout_url; // spec: _top handoff
						return;
					}
					self.done(act === 'approval' ? t.approval : t.done, b.data.manage_url);
				}).catch(function () {
					self.showErr(t.error, {});
				}).finally(function () {
					if (!self.completed) {
						self.btn.disabled = false;
						self.btn.textContent = d.approvalRequired ? t.book : t.payNow;
					}
				});
		}

		done(msg, manageUrl) {
			this.completed = true;
			var s = this.status;
			s.textContent = '';
			var ok = el('div', { 'class': 'ok' }, msg + ' ');
			if (manageUrl) {
				var a = el('a', { href: manageUrl, target: '_top', rel: 'noopener' }, this.t.manage);
				ok.appendChild(a);
			}
			s.appendChild(ok);
			this.btn.disabled = true;
		}

		clearErrs() {
			this.status.textContent = '';
			this.box.querySelectorAll('[aria-invalid]').forEach(function (n) { n.removeAttribute('aria-invalid'); });
			this.box.querySelectorAll('.err').forEach(function (n) { n.remove(); });
		}

		showErr(msg, fields) {
			var s = this.status;
			s.textContent = '';
			s.appendChild(el('div', { 'class': 'bad' }, msg));
			var map = { buyer_name: this.nameInput, buyer_email: this.emailInput,
				consent: this.tosCb, seats: this.seatInput, lines: this.occSel,
				billing_country: this.countryInput, vat_id: this.vatInput,
				pay_method: this.pmPsp };
			for (var k in (fields || {})) {
				var input = map[k];
				if (input) {
					input.setAttribute('aria-invalid', 'true');
					var err = el('p', { 'class': 'err' }, fields[k]);
					var host = input.closest('.row') || input.parentNode;
					host.parentNode.insertBefore(err, host.nextSibling);
				}
			}
		}
	});
})();

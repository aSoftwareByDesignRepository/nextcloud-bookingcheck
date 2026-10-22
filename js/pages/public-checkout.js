/* Public checkout — submit → PSP redirect or done. Public API lives at
 * appBase + '/pub/api/...' (not under the admin /api base).
 *
 * Contract (POST /pub/api/orders):
 *   {offering_id, buyer_name, buyer_email, locale, consent?,
 *    lines:[{target_type,target_id,seats,price_cat_id?}],
 *    addons:[{addon_id,qty}], promo?, voucher?, idem_token, hp_website?}
 * Response data.action: 'pay' → checkout_url, 'done'/'approval' → manage_url,
 * 'replay' → the same idem_token already created the order.
 */
(function () {
	'use strict';
	var form = document.getElementById('bck-checkout');
	if (!form || !window.BCK) { return; }
	var offeringId = parseInt(form.getAttribute('data-bck-offering-id'), 10);
	var approval = form.getAttribute('data-bck-approval') === '1';
	var isPass = form.getAttribute('data-bck-pass') === '1';
	var unitCents = parseInt(form.getAttribute('data-bck-unit'), 10) || 0;
	var currency = form.getAttribute('data-bck-currency') || 'EUR';
	var submitBtn = document.getElementById('bck-pay-btn');
	var promoHint = document.getElementById('bck-promo-hint');

	/* One idempotency token per page load — double-submit or a lost response
	 * retries with the SAME token so the server replays instead of
	 * double-holding seats. */
	var idemToken = (window.crypto && crypto.randomUUID)
		? crypto.randomUUID().replace(/-/g, '').slice(0, 32)
		: String(Date.now()) + Math.random().toString(36).slice(2, 18);

	function pubApi(path, payload) {
		return fetch(BCK.appBase() + path, {
			method: payload ? 'POST' : 'GET',
			credentials: 'same-origin',
			headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
			body: payload ? JSON.stringify(payload) : undefined
		}).then(function (r) { return r.json().catch(function () { return {}; }); });
	}

	function formatCents(cents) {
		return currency + ' ' + (cents / 100).toFixed(2).replace('.', ',');
	}

	function selectedUnit() {
		var cat = document.getElementById('bck-cat');
		if (cat && cat.value) {
			var opt = cat.options[cat.selectedIndex];
			return parseInt(opt.getAttribute('data-price'), 10) || 0;
		}
		return unitCents;
	}

	var promoBps = 0; // applied percent code — shown in the live total

	function renderTotal() {
		var el = document.getElementById('bck-total');
		if (!el) { return; }
		var qty = parseInt((form.querySelector('[name="qty"]') || {}).value, 10) || 1;
		var cents = selectedUnit() * qty;
		form.querySelectorAll('[data-addon-id]').forEach(function (cb) {
			if (cb.checked) { cents += parseInt(cb.getAttribute('data-price'), 10) || 0; }
		});
		if (promoBps > 0 && promoBps < 10000) {
			cents = Math.round(cents * (10000 - promoBps) / 10000);
		}
		el.textContent = cents > 0 ? formatCents(cents) : '';
	}

	/* Qty must never exceed the picked date's availability — clamping
	 * beats a guaranteed server rejection. */
	var qtyInput = form.querySelector('[name="qty"]');
	function clampQty() {
		if (!qtyInput || isPass) { return; }
		var occ = form.querySelector('[name="occ"]:checked');
		var avail = occ ? parseInt(occ.getAttribute('data-available'), 10) : 0;
		if (avail > 0) {
			qtyInput.max = String(Math.min(50, avail));
			if (parseInt(qtyInput.value, 10) > avail) {
				qtyInput.value = String(avail);
			}
		}
	}
	form.addEventListener('change', function (ev) {
		if (ev.target && ev.target.name === 'occ') { clampQty(); }
	});
	clampQty();

	form.addEventListener('change', renderTotal);
	form.addEventListener('input', renderTotal);
	renderTotal();

	var applyBtn = document.getElementById('bck-promo-apply');
	if (applyBtn) {
		applyBtn.addEventListener('click', function () {
			var code = String((form.querySelector('[name="promo"]') || {}).value || '').trim();
			if (!code || !promoHint) { return; }
			applyBtn.disabled = true;
			pubApi('/pub/api/promo/validate', { code: code, offering_id: offeringId }).then(function (body) {
				applyBtn.disabled = false;
				var ok = body && body.success && body.data && body.data.valid;
				// percent codes reflect straight into the live total — fixed
				// amounts and voucher credit stay server-priced (checkout shows it)
				promoBps = (ok && body.data.kind === 'percent')
					? (parseInt(body.data.percent_bps, 10) || 0) : 0;
				promoHint.textContent = ok
					? (promoBps > 0
						? BCK.tf('Code applied: %s% off.', [String(promoBps / 100)])
						: BCK.t('Code applied.'))
					: BCK.t('This code is not valid.');
				renderTotal();
			}).catch(function () { applyBtn.disabled = false; });
		});
		// editing the code after applying must not keep the stale discount
		var promoInput = form.querySelector('[name="promo"]');
		if (promoInput) {
			promoInput.addEventListener('input', function () {
				if (promoBps !== 0) { promoBps = 0; renderTotal(); }
			});
		}
	}

	function showFieldError(key, message) {
		/* Server keys: buyer_name, buyer_email, consent, lines / lines.N.*,
		 * addons / addons.N, promo, voucher, idem_token, offering_id */
		var errKey = key;
		if (key.indexOf('lines') === 0) { errKey = 'lines'; }
		else if (key.indexOf('addons') === 0) { errKey = 'addons'; }
		else if (key === 'voucher') { errKey = 'promo'; } // one code box serves both
		var err = document.getElementById('bck-' + errKey + '-error');
		var input = form.querySelector('[name="' + key + '"]')
			|| form.querySelector('[name="' + errKey + '"]');
		if (errKey === 'lines') { input = form.querySelector('[name="occ"]'); }
		if (input) { input.setAttribute('aria-invalid', 'true'); }
		if (err) { err.textContent = message; err.hidden = false; }
	}

	function clearErrors() {
		form.querySelectorAll('.bck-field-error').forEach(function (el) {
			el.hidden = true; el.textContent = '';
		});
		form.querySelectorAll('[aria-invalid]').forEach(function (el) {
			el.removeAttribute('aria-invalid');
		});
	}

	/* Forgiving inputs: catch the obvious mistakes before the server
	 * round-trip — same field-error slots, same aria-invalid contract. */
	function preValidate() {
		var bad = [];
		if (!isPass && !form.querySelector('[name="occ"]:checked')) {
			bad.push(['lines', BCK.t('Please pick a date.')]);
		}
		var name = form.querySelector('[name="buyer_name"]');
		if (name && String(name.value).trim().length < 2) {
			bad.push(['buyer_name', BCK.t('Please tell us your name.')]);
		}
		var email = form.querySelector('[name="buyer_email"]');
		if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(String(email.value).trim())) {
			bad.push(['buyer_email', BCK.t('That email looks wrong — please check it.')]);
		}
		var consent = form.querySelector('[name="consent"]');
		if (consent && !consent.checked) {
			bad.push(['consent', BCK.t('Please accept the terms to continue.')]);
		}
		if (!bad.length) { return true; }
		bad.forEach(function (pair) { showFieldError(pair[0], pair[1]); });
		var first = form.querySelector('[aria-invalid="true"]');
		if (first && first.focus) { first.focus(); }
		return false;
	}

	form.addEventListener('submit', function (ev) {
		ev.preventDefault();
		clearErrors();
		if (!preValidate()) { return; }
		submitBtn.disabled = true;
		submitBtn.setAttribute('aria-busy', 'true');

		var fd = new FormData(form);
		var occ = String(fd.get('occ') || '').split(':');
		var code = String(fd.get('promo') || '').trim();
		var consentEl = form.querySelector('[name="consent"]');
		// pooled slots arrive as "slot:<id>:<resource>" — the pool pick
		// IS the seat target (capacity lives on bc_slot_res)
		var line = !isPass && occ.length >= 2 ? {
			target_type: occ[0],
			target_id: parseInt(occ[1], 10),
			seats: parseInt(fd.get('qty'), 10) || 1,
			price_cat_id: fd.get('price_cat_id') ? parseInt(fd.get('price_cat_id'), 10) : null,
			resource_id: occ.length === 3 ? parseInt(occ[2], 10) : null
		} : null;
		var payload = {
			offering_id: offeringId,
			buyer_name: String(fd.get('buyer_name') || ''),
			buyer_email: String(fd.get('buyer_email') || ''),
			locale: document.documentElement.lang || 'en',
			lines: line ? [line] : [],
			pass_qty: isPass ? (parseInt(fd.get('qty'), 10) || 1) : null,
			pay_method: String(fd.get('pay_method') || '') || null,
			addons: [],
			buyer_company: String(fd.get('buyer_company') || '').trim() || null,
			billing_country: String(fd.get('billing_country') || '').trim() || null,
			vat_id: String(fd.get('vat_id') || '').trim() || null,
			promo: code || null,
			voucher: code || null,
			consent: consentEl ? (consentEl.checked ? '1' : '') : '',
			idem_token: idemToken,
			hp_website: String(fd.get('hp_website') || '')
		};
		form.querySelectorAll('[data-addon-id]').forEach(function (cb) {
			if (cb.checked) {
				payload.addons.push({ addon_id: parseInt(cb.getAttribute('data-addon-id'), 10), qty: 1 });
			}
		});

		var notBusy = function () {
			submitBtn.disabled = false;
			submitBtn.removeAttribute('aria-busy');
		};
		pubApi('/pub/api/orders', payload).then(function (body) {
			if (body && body.success && body.data) {
				var d = body.data;
				if (d.action === 'pay' && d.checkout_url) {
					window.location.href = d.checkout_url;
					return;
				}
				if ((d.action === 'done' || d.action === 'approval') && d.manage_url) {
					window.location.href = d.manage_url;
					return;
				}
				if (d.action === 'replay') {
					if (BCK.toast) {
						BCK.toast.info(BCK.t('This booking was already submitted — please check your email for the confirmation link.'));
					}
					notBusy();
					return;
				}
			}
			notBusy();
			if (body && body.fields) {
				Object.keys(body.fields).forEach(function (k) {
					showFieldError(k, body.fields[k]);
				});
				var first = form.querySelector('[aria-invalid="true"]');
				if (first && first.focus) { first.focus(); }
			} else if (body && body.message && BCK.toast) {
				BCK.toast.error(body.message);
			}
		}).catch(function () {
			notBusy();
			if (BCK.toast) { BCK.toast.error(BCK.t('Something went wrong — please try again.')); }
		});
	});
}());

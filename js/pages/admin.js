/* Admin pages — BCK.api contract: {json:} in, resolved data out, ApiError on failure. */
(function () {
	'use strict';
	if (!window.BCK) { return; }

	function reload() { window.location.reload(); }
	function fail(e) { BCK.handleError(e); }

	// generic JSON form posts: <form data-bck-form data-bck-method="PUT"
	// action="/apps/bookingcheck/api/..."> — FormData → JSON, then follow up.
	document.addEventListener('submit', function (ev) {
		var form = ev.target.closest('form[data-bck-form]');
		if (!form) { return; }
		ev.preventDefault();
		var url = form.getAttribute('action') || '';
		var base = BCK.appBase() + (BCK.boot.apiBase || '/api');
		var path = url.indexOf(base) === 0 ? url.slice(base.length) : url;
		var payload = {};
		new FormData(form).forEach(function (v, k) { payload[k] = String(v); });
		// offering form: price is entered in currency units, API wants cents
		if (form.getAttribute('data-bck-form') === 'offering' && payload.price !== undefined) {
			var p = parseFloat(String(payload.price).replace(',', '.'));
			payload.price_cents = isFinite(p) ? String(Math.round(p * 100)) : '';
			delete payload.price;
		}
		var btn = form.querySelector('[type="submit"]');
		if (btn) { btn.disabled = true; }
		BCK.api(path, { method: form.getAttribute('data-bck-method') || 'POST', json: payload })
			.then(function (d) {
				window.location.href = BCK.appBase() + '/offerings' + (d && d.id ? '/' + d.id : '');
			})
			.catch(function (e) { fail(e); if (btn) { btn.disabled = false; } });
	});

	// generic DELETE buttons: <button data-bck-delete="/api-suffix">
	document.addEventListener('click', function (ev) {
		var btn = ev.target.closest('[data-bck-delete]');
		if (!btn) { return; }
		btn.disabled = true;
		BCK.api(btn.getAttribute('data-bck-delete'), { method: 'DELETE' })
			.then(reload)
			.catch(function (e) { fail(e); btn.disabled = false; });
	});

	// order detail actions
	var orderEl = document.querySelector('[data-bck-order-id]');
	if (orderEl) {
		var oid = orderEl.getAttribute('data-bck-order-id');
		var currency = orderEl.getAttribute('data-bck-order-currency') || '';
		document.addEventListener('click', function (ev) {
			var btn = ev.target.closest('[data-bck-order-action]');
			if (!btn) { return; }
			var action = btn.getAttribute('data-bck-order-action');
			if (action === 'refund') {
				// quote → confirm → commit: the server only accepts the exact
				// quoted amount echoed back (full refunds only in v1).
				btn.disabled = true;
				BCK.api('/orders/' + oid + '/refund-quote', { method: 'POST', json: {} })
					.then(function (b) {
						var q = (b && b.data) || {};
						var cur = q.currency || currency;
						var text = (q.amount_cents / 100).toFixed(2).replace('.', ',');
						var wrap = BCK.el('div', { class: 'bck-field' });
						wrap.innerHTML =
							'<p>' + BCK.esc(BCK.t('This refunds the full %1$s %2$s at the payment provider, releases the seats, and revokes all tickets.', [cur, text])) + '</p>';
						BCK.openDialog({
							title: BCK.t('Refund this booking?'),
							body: wrap,
							submitLabel: BCK.t('Refund'),
							danger: true,
							onSubmit: function () {
								return BCK.api('/orders/' + oid + '/refund', {
									method: 'POST',
									json: { amount_cents: q.amount_cents }
								}).then(reload);
							}
						});
					})
					.catch(function (e) { fail(e); })
					.finally(function () { btn.disabled = false; });
				return;
			}
			btn.disabled = true;
			BCK.api('/orders/' + oid + '/' + action, { method: 'POST', json: {} })
				.then(reload)
				.catch(function (e) { fail(e); btn.disabled = false; });
		});
	}

	// review moderation — route is POST /api/reviews/{id} with {to}
	document.addEventListener('click', function (ev) {
		var btn = ev.target.closest('[data-bck-moderate]');
		if (!btn) { return; }
		btn.disabled = true;
		BCK.api('/reviews/' + btn.getAttribute('data-bck-moderate') , {
			method: 'POST',
			json: { to: btn.getAttribute('data-bck-to') }
		}).then(reload)
			.catch(function (e) { fail(e); btn.disabled = false; });
	});

	// abuse report moderation — POST abuse/{id}/resolve {action}
	document.addEventListener('click', function (ev) {
		var btn = ev.target.closest('[data-bck-abuse]');
		if (!btn) { return; }
		btn.disabled = true;
		BCK.api('/abuse/' + btn.getAttribute('data-bck-abuse') + '/resolve', {
			method: 'POST',
			json: { action: btn.getAttribute('data-bck-action') }
		}).then(reload)
			.catch(function (e) { fail(e); btn.disabled = false; });
	});

	// waitlist remove
	document.addEventListener('click', function (ev) {
		var btn = ev.target.closest('[data-bck-waitlist-remove]');
		if (!btn) { return; }
		btn.disabled = true;
		BCK.api('/waitlist/' + btn.getAttribute('data-bck-waitlist-remove'), { method: 'DELETE' })
			.then(reload)
			.catch(function (e) { fail(e); btn.disabled = false; });
	});

	// roster picker → load attendee list
	var picker = document.querySelector('[data-bck-roster-picker]');
	var listBox = document.getElementById('bck-roster-list');
	if (picker && listBox) {
		picker.addEventListener('change', function () {
			var sel = picker.value.split(':');
			if (sel.length !== 2 || !sel[1]) { return; }
			BCK.api('/targets/' + sel[0] + '/' + sel[1] + '/roster', { method: 'GET' })
				.then(function (data) {
					var rows = (data && data.roster) || [];
					if (!rows.length) {
						listBox.innerHTML = '<div class="bck-card__body"><div class="bck-empty-state"><p class="bck-empty-state__text">Nobody booked yet.</p></div></div>';
						return;
					}
					var html = '<div class="bck-card__body bck-card__body--table"><div class="bck-table-wrap"><table class="bck-table"><thead><tr>'
						+ '<th>Name</th><th>Seat</th><th>Status</th><th></th></tr></thead><tbody>';
					rows.forEach(function (t) {
						var checked = !!t.checked_in_at;
						var notes = (t.answers || []).map(function (a) {
							return BCK.esc(a.label + ': ' + a.value);
						}).join(' · ');
						html += '<tr><td>' + BCK.esc(t.attendee_name || '')
							+ (notes ? '<br><span class="bck-roster-notes">' + notes + '</span>' : '') + '</td>'
							+ '<td>' + BCK.esc(t.seat_no || '') + '</td>'
							+ '<td>' + (checked ? 'in' : 'out') + '</td>'
							+ '<td><button class="bck-btn bck-btn--small bck-btn--primary" data-bck-checkin="'
							+ t.id + '" data-bck-undo="' + (checked ? '1' : '0') + '">'
							+ (checked ? 'Undo' : 'Check in') + '</button></td></tr>';
					});
					listBox.innerHTML = html + '</tbody></table></div></div>';
				})
				.catch(fail);
		});
	}

	// scan-page check-in — QR-token bound, works for admin and door sessions
	document.addEventListener('click', function (ev) {
		var btn = ev.target.closest('[data-bck-scan-checkin]');
		if (!btn) { return; }
		btn.disabled = true;
		BCK.api('/scan/check-in', { method: 'POST', json: { code: btn.getAttribute('data-bck-scan-checkin') } })
			.then(function (d) {
				// AC-2: a second scan is a warning, never a silent green
				var mark = (d && d.already) ? ' — ALREADY IN' : ' — in';
				var li = btn.closest('li');
				if (li) {
					var s = li.querySelector('span');
					if (s) { s.textContent = s.textContent + mark; }
				}
				var p = btn.parentElement && btn.parentElement.querySelector('.bck-callout__text');
				if (p && !li) {
					p.textContent = (d && d.already)
						? (d.attendee_name || 'Ticket') + ' — already checked in earlier'
						: (d.attendee_name || 'Ticket') + ' — checked in';
				}
				btn.remove();
			})
			.catch(function (e) { fail(e); btn.disabled = false; });
	});

	// check-in / undo (roster + scan share this)
	document.addEventListener('click', function (ev) {
		var btn = ev.target.closest('[data-bck-checkin]');
		if (!btn) { return; }
		var undo = btn.getAttribute('data-bck-undo') === '1';
		var inScan = btn.closest('#bck-scan-result') !== null;
		btn.disabled = true;
		BCK.api('/attendees/' + btn.getAttribute('data-bck-checkin') + '/check-in',
			{ method: undo ? 'DELETE' : 'POST' })
			.then(function () {
				if (inScan) {
					// multi-ticket door flow — keep the list, swap the row
					var li = btn.closest('li');
					if (li) {
						var s = li.querySelector('span');
						if (s) { s.textContent = s.textContent + ' — in'; }
					}
					btn.remove();
					return;
				}
				reload();
			})
			.catch(function (e) { fail(e); btn.disabled = false; });
	});

	// door entry — a companion token mints a least-privileged scan session
	var doorForm = document.getElementById('bck-door-form');
	if (doorForm) {
		doorForm.addEventListener('submit', function (ev) {
			ev.preventDefault();
			var err = document.getElementById('bck-door-error');
			var token = String(new FormData(doorForm).get('token') || '').trim();
			if (!token) { return; }
			var btn = doorForm.querySelector('button[type="submit"]');
			if (btn) { btn.disabled = true; }
			BCK.api('/scan/session', { method: 'POST', json: { token: token } })
				.then(function () { window.location.reload(); })
				.catch(function (e) {
					if (err) {
						err.textContent = (e && e.message) || 'Invalid device token.';
						err.hidden = false;
					}
					if (btn) { btn.disabled = false; }
				});
		});
	}
	var doorOut = document.getElementById('bck-door-out');
	if (doorOut) {
		doorOut.addEventListener('click', function () {
			doorOut.disabled = true;
			BCK.api('/scan/session-close', { method: 'POST', json: {} })
				.then(function () { window.location.reload(); })
				.catch(function () { doorOut.disabled = false; });
		});
	}

	// scan form — lookup then one-tap check-in
	var scanForm = document.getElementById('bck-scan-form');
	var scanResult = document.getElementById('bck-scan-result');
	if (scanForm && scanResult) {
		scanForm.addEventListener('submit', function (ev) {
			ev.preventDefault();
			var code = String(new FormData(scanForm).get('code') || '').trim();
			if (!code) { return; }
			BCK.api('/scan/lookup', { method: 'POST', json: { code: code } })
				.then(function (d) {
					scanResult.innerHTML = '';
					// check-in re-posts the ticket's QR token (never an
					// enumerable id) through the door-safe scan endpoint
					var mkBtn = function (qr) {
						var btn = document.createElement('button');
						btn.type = 'button';
						btn.className = 'bck-btn bck-btn--primary';
						btn.setAttribute('data-bck-scan-checkin', String(qr));
						btn.textContent = 'Check in';
						return btn;
					};
					if (d.tickets) {
						// order-ref lookup — one booking, a row per live ticket
						scanResult.className = 'bck-callout bck-callout--success';
						var hp = document.createElement('p');
						hp.className = 'bck-callout__text';
						hp.textContent = d.order_ref + ':';
						scanResult.appendChild(hp);
						var ul = document.createElement('ul');
						ul.className = 'bck-scan-list';
						d.tickets.forEach(function (t) {
							var li = document.createElement('li');
							var span = document.createElement('span');
							span.textContent = (t.attendee_name || 'Ticket')
								+ (t.seat_no ? ' · seat ' + t.seat_no : '')
								+ (t.checked_in_at ? ' — in' : '');
							li.appendChild(span);
							if (!t.checked_in_at && t.qr) { li.appendChild(mkBtn(t.qr)); }
							ul.appendChild(li);
						});
						scanResult.appendChild(ul);
					} else {
						scanResult.className = 'bck-callout bck-callout--' + (d.checked_in ? 'warning' : 'success');
						var p = document.createElement('p');
						p.className = 'bck-callout__text';
						p.textContent = (d.attendee_name || 'Ticket') + ' — ' + (d.checked_in ? 'already checked in' : 'valid');
						scanResult.appendChild(p);
						if (!d.checked_in && d.qr) { scanResult.appendChild(mkBtn(d.qr)); }
					}
					scanResult.hidden = false;
				})
				.catch(function (e) {
					scanResult.className = 'bck-callout bck-callout--danger';
					scanResult.textContent = (e && e.message) || 'No ticket found for this code.';
					scanResult.hidden = false;
				});
		});
	}

	// per-offering integrations: <input type=checkbox data-bck-integration="deck|talk" data-bck-offering="id">
	document.addEventListener('change', function (ev) {
		var el = ev.target.closest('[data-bck-integration]');
		if (!el) { return; }
		var oid = el.getAttribute('data-bck-offering');
		var kind = el.getAttribute('data-bck-integration');
		el.disabled = true;
		BCK.api('/offerings/' + oid + '/integration', {
			method: 'POST', json: { kind: kind, on: el.checked ? '1' : '0' }
		}).then(function (d) {
			if (d && d.unavailable) {
				el.checked = false;
				BCK.toast('error', 'That integration is not installed.');
			} else {
				BCK.toast('success', el.checked ? 'Connected.' : 'Disconnected.');
			}
			el.disabled = false;
		}).catch(function (e) {
			el.checked = !el.checked; // revert on failure
			fail(e);
			el.disabled = false;
		});
	});

	// ---- "New …" dialogs: promo / voucher / resource / webhook ----
	function field(labelText, control, hint) {
		var wrap = BCK.el('div', { class: 'bck-field' });
		var label = BCK.el('label', { class: 'bck-label', text: labelText });
		label.setAttribute('for', control.id);
		wrap.appendChild(label);
		wrap.appendChild(control);
		if (hint) { wrap.appendChild(BCK.el('p', { class: 'bck-field__hint', text: hint })); }
		return wrap;
	}
	function input(name, attrs) {
		var i = BCK.el('input', attrs || {});
		i.name = name; i.id = 'bck-dlg-' + name;
		return i;
	}
	function moneyToCents(v) {
		var p = parseFloat(String(v).replace(',', '.'));
		return isFinite(p) ? String(Math.round(p * 100)) : '';
	}

	var promoBtn = document.getElementById('bck-new-promo');
	if (promoBtn) {
		promoBtn.addEventListener('click', function () {
			var kindSel = BCK.el('select', {});
			kindSel.name = 'kind'; kindSel.id = 'bck-dlg-kind';
			[['percent', BCK.t('Percent off')], ['fixed', BCK.t('Fixed amount off')]]
				.forEach(function (o) {
					var op = document.createElement('option');
					op.value = o[0]; op.textContent = o[1];
					kindSel.appendChild(op);
				});
			var pctWrap = field(BCK.t('Percent'), input('percent_bps',
				{ type: 'number', min: '1', max: '10000', inputmode: 'numeric' }), BCK.t('In basis points — 1000 = 10 percent.'));
			var amtWrap = field(BCK.t('Amount'), input('amount', { type: 'number', min: '0.01', step: '0.01', inputmode: 'decimal' }));
			var curWrap = field(BCK.t('Currency'), input('currency',
				{ type: 'text', minlength: '3', maxlength: '3', pattern: '[A-Za-z]{3}', value: 'EUR' }));
			amtWrap.hidden = true; curWrap.hidden = true;
			kindSel.addEventListener('change', function () {
				var fixed = kindSel.value === 'fixed';
				pctWrap.hidden = fixed; amtWrap.hidden = !fixed; curWrap.hidden = !fixed;
			});
			var body = BCK.el('div', {});
			body.appendChild(field(BCK.t('Code'), input('code',
				{ type: 'text', required: 'required', maxlength: '32', pattern: '[A-Za-z0-9_\\-]{3,32}' }), BCK.t('3–32 chars, A–Z 0–9.')));
			body.appendChild(field(BCK.t('Discount type'), kindSel));
			body.appendChild(pctWrap); body.appendChild(amtWrap); body.appendChild(curWrap);
			BCK.openDialog({
				title: BCK.t('New promotion'),
				body: body,
				submitLabel: BCK.t('Create'),
				onSubmit: function (fd) {
					var payload = { code: String(fd.get('code') || ''), kind: kindSel.value };
					if (kindSel.value === 'percent') {
						payload.percent_bps = String(fd.get('percent_bps') || '');
					} else {
						payload.amount_cents = moneyToCents(fd.get('amount'));
						payload.currency = String(fd.get('currency') || '');
					}
					return BCK.api('/promos', { method: 'POST', json: payload }).then(reload);
				}
			});
		});
	}

	var voucherBtn = document.getElementById('bck-new-voucher');
	if (voucherBtn) {
		voucherBtn.addEventListener('click', function () {
			var body = BCK.el('div', {});
			body.appendChild(field(BCK.t('Credit'), input('credit',
				{ type: 'number', min: '0.01', step: '0.01', inputmode: 'decimal', required: 'required' })));
			body.appendChild(field(BCK.t('Currency'), input('currency',
				{ type: 'text', minlength: '3', maxlength: '3', pattern: '[A-Za-z]{3}', value: 'EUR', required: 'required' })));
			body.appendChild(field(BCK.t('Max uses'), input('max_uses',
				{ type: 'number', min: '1', max: '100000', inputmode: 'numeric', value: '1' })));
			BCK.openDialog({
				title: BCK.t('New voucher'),
				body: body,
				submitLabel: BCK.t('Create'),
				onSubmit: function (fd) {
					return BCK.api('/vouchers', {
						method: 'POST',
						json: {
							credit_cents: moneyToCents(fd.get('credit')),
							currency: String(fd.get('currency') || ''),
							max_uses: String(fd.get('max_uses') || '1')
						}
					}).then(function (d) {
						// the code is minted server-side — show it once, large
						var code = (d && d.code) || '';
						var p = BCK.el('p', { class: 'bck-callout__text' });
						p.innerHTML = 'Voucher code: <code>' + BCK.esc(code) + '</code>';
						BCK.openDialog({ title: BCK.t('Voucher created'), body: p, submitLabel: BCK.t('Done') });
						reload();
					});
				}
			});
		});
	}

	var resBtn = document.getElementById('bck-new-resource');
	if (resBtn) {
		resBtn.addEventListener('click', function () {
			var offerings = [];
			try { offerings = JSON.parse((document.getElementById('bck-resource-offerings') || {}).textContent || '[]'); } catch (e) {}
			if (!offerings.length) {
				BCK.toast('error', BCK.t('Create an offering first.'));
				return;
			}
			var offSel = BCK.el('select', {});
			offSel.name = 'offering_id'; offSel.id = 'bck-dlg-offering_id';
			offerings.forEach(function (o) {
				var op = document.createElement('option');
				op.value = String(o.id); op.textContent = o.title;
				offSel.appendChild(op);
			});
			var body = BCK.el('div', {});
			body.appendChild(field(BCK.t('Offering'), offSel));
			body.appendChild(field(BCK.t('Name'), input('name',
				{ type: 'text', required: 'required', maxlength: '190' }), BCK.t('E.g. “Room A” or “Trainer: Kim”.')));
			body.appendChild(field(BCK.t('Capacity'), input('capacity',
				{ type: 'number', min: '1', max: '100000', inputmode: 'numeric', required: 'required' }), BCK.t('How many seats this pool sells per slot.')));
			BCK.openDialog({
				title: BCK.t('New resource'),
				body: body,
				submitLabel: BCK.t('Create'),
				onSubmit: function (fd) {
					return BCK.api('/resources', {
						method: 'POST',
						json: {
							offering_id: String(fd.get('offering_id') || ''),
							name: String(fd.get('name') || ''),
							capacity: String(fd.get('capacity') || '')
						}
					}).then(reload);
				}
			});
		});
	}

	var whBtn = document.getElementById('bck-new-webhook');
	if (whBtn) {
		whBtn.addEventListener('click', function () {
			var events = [];
			try { events = JSON.parse((document.getElementById('bck-webhook-events') || {}).textContent || '[]'); } catch (e) {}
			var body = BCK.el('div', {});
			body.appendChild(field(BCK.t('Endpoint URL'), input('url',
				{ type: 'url', required: 'required', placeholder: 'https://example.com/hook' }), BCK.t('https:// only — verified again at delivery time.')));
			var evField = BCK.el('fieldset', { class: 'bck-fieldset' });
			evField.appendChild(BCK.el('legend', { class: 'bck-fieldset__legend', text: BCK.t('Events') }));
			events.forEach(function (name) {
				var lab = BCK.el('label', { class: 'bck-check' });
				var cb = document.createElement('input');
				cb.type = 'checkbox'; cb.name = 'events[]'; cb.value = name;
				lab.appendChild(cb);
				lab.appendChild(BCK.el('span', { text: name }));
				evField.appendChild(lab);
			});
			body.appendChild(evField);
			BCK.openDialog({
				title: BCK.t('New webhook'),
				body: body,
				submitLabel: BCK.t('Create'),
				onSubmit: function (fd) {
					var picked = fd.getAll('events[]');
					if (!picked.length) {
						return Promise.reject(new Error(BCK.t('Pick at least one event.')));
					}
					return BCK.api('/webhooks', {
						method: 'POST',
						json: { url: String(fd.get('url') || ''), events: picked }
					}).then(reload);
				}
			});
		});
	}

	// webhook secret rotation
	document.addEventListener('click', function (ev) {
		var btn = ev.target.closest('[data-bck-webhook-rotate]');
		if (!btn) { return; }
		btn.disabled = true;
		BCK.api('/webhooks/' + btn.getAttribute('data-bck-webhook-rotate'), {
			method: 'PUT', json: { rotate_secret: '1' }
		}).then(function () {
			BCK.toast('success', 'Secret rotated — old one stays valid 24h.');
			btn.disabled = false;
		}).catch(function (e) { fail(e); btn.disabled = false; });
	});

	/* ---------- offering detail page ---------- */

	var detailRoot = document.querySelector('[data-bck-offering-detail]');
	if (detailRoot) {
		var offeringId = detailRoot.getAttribute('data-bck-offering-detail');

		function esc(s) { return BCK.esc(String(s == null ? '' : s)); }
		function err(el, msg) { if (el) { el.hidden = !msg; el.textContent = msg || ''; } }

		function tableBox(cols, rows, emptyMsg) {
			if (!rows.length) {
				return '<div class="bck-empty-state"><p class="bck-empty-state__text">'
					+ esc(emptyMsg) + '</p></div>';
			}
			var html = '<div class="bck-table-wrap"><table class="bck-table"><thead><tr>'
				+ cols.map(function (c) { return '<th>' + esc(c) + '</th>'; }).join('')
				+ '</tr></thead><tbody>' + rows.join('') + '</tbody></table></div>';
			return html;
		}

		function cents(c) { return (parseInt(c, 10) / 100).toFixed(2); }
		function dt(s) { return s ? String(s).slice(0, 16).replace('T', ' ') : '—'; }

		// ---- summary ----
		var summaryBox = document.getElementById('bck-offering-summary');
		var offering = null;
		BCK.api('/offerings/' + offeringId).then(function (d) {
			offering = d && d.offering;
			if (!offering) { throw new Error('empty'); }
			summaryBox.innerHTML = '<div class="bck-card__body">'
				+ '<h2 class="bck-card__title">' + esc(offering.title) + '</h2>'
				+ '<p>' + esc(offering.type) + ' · '
				+ esc(offering.currency) + ' ' + cents(offering.priceCents)
				+ ' · <span class="bck-badge bck-badge--info">' + esc(offering.status) + '</span></p>'
				+ '</div>';
			if (offering.type === 'timeslot') {
				var tplField = document.getElementById('bck-occ-tpl-field');
				var seriesChk = document.getElementById('bck-occ-series');
				if (tplField) { tplField.hidden = false; }
				if (seriesChk) { seriesChk.closest('.bck-field').hidden = true; }
				loadTemplates();
			}
		}).catch(function () {
			summaryBox.innerHTML = '<div class="bck-card__body"><p class="bck-empty-state__text">'
				+ BCK.t('Offering not found.') + '</p></div>';
		});

		// ---- occurrences ----
		var occList = document.getElementById('bck-occ-list');
		var tplSelect = document.getElementById('bck-occ-tpl');
		function loadOcc() {
			BCK.api('/offerings/' + offeringId + '/slots').then(function (d) {
				var rows = [];
				var items = (d && (d.events || d.slots)) || [];
				items.forEach(function (e) {
					var del = (parseInt(e.sold, 10) === 0 && parseInt(e.held, 10) === 0)
						? '<button class="bck-btn bck-btn--small" data-bck-del-occ="' + e.id + '">'
							+ BCK.t('Delete') + '</button>' : '';
					var edit = '<button class="bck-btn bck-btn--small" data-bck-edit-occ="' + e.id
						+ '" data-starts="' + esc(e.startsAt) + '" data-ends="' + esc(e.endsAt)
						+ '" data-cap="' + parseInt(e.capacity, 10) + '">'
						+ BCK.t('Edit') + '</button>';
					rows.push('<tr><td>' + esc(dt(e.startsAt)) + '</td><td>' + esc(dt(e.endsAt))
						+ '</td><td>' + e.sold + '/' + e.capacity + '</td><td>'
						+ esc(e.status || '') + '</td><td>' + edit + del + '</td></tr>');
				});
				occList.innerHTML = tableBox(
					[BCK.t('Starts'), BCK.t('Ends'), BCK.t('Sold/Seats'), BCK.t('Status'), ''],
					rows, BCK.t('No dates yet — add the first one below.'));
			}).catch(fail);
		}
		loadOcc();

		// ---- attendee uploads (moderation) ----
		var upList = document.getElementById('bck-upload-list');
		function loadUploads() {
			if (!upList) { return; }
			BCK.api('/offerings/' + offeringId + '/uploads').then(function (d) {
				var items = (d && d.uploads) || [];
				upList.innerHTML = tableBox(
					[BCK.t('Name'), BCK.t('By'), BCK.t('Size'), BCK.t('Date'), ''],
					items.map(function (u) {
						var mb = (parseInt(u.sizeBytes, 10) / 1048576).toFixed(1);
						return '<tr><td><a href="' + BCK.appBase() + '/api/uploads/'
							+ u.id + '/file">' + BCK.tf('File #%s', [String(u.id)]) + '</a></td>'
							+ '<td>' + esc(u.displayName) + '</td>'
							+ '<td>' + mb + ' MB</td><td>' + esc(dt(u.createdAt)) + '</td>'
							+ '<td><button class="bck-btn bck-btn--small" data-bck-delete="/uploads/'
							+ u.id + '">' + BCK.t('Remove') + '</button></td></tr>';
					}), BCK.t('No shared files yet.'));
			}).catch(function () {
				upList.innerHTML = '';
			});
		}
		loadUploads();

		// ---- broadcasts ----
		var bcForm = document.getElementById('bck-broadcast-form');
		var bcOccField = document.getElementById('bck-bc-occ-field');
		var bcOccSel = document.getElementById('bck-bc-occ');
		var bcList = document.getElementById('bck-broadcast-list');
		function loadBroadcasts() {
			if (!bcList) { return; }
			BCK.api('/offerings/' + offeringId + '/broadcasts').then(function (d) {
				var items = (d && d.broadcasts) || [];
				bcList.innerHTML = tableBox(
					[BCK.t('Subject'), BCK.t('Scope'), BCK.t('Sent'), BCK.t('Recipients')],
					items.map(function (b) {
						return '<tr><td>' + esc(b.subject) + '</td><td>' + esc(b.scope)
							+ '</td><td>' + esc(dt(b.sentAt)) + '</td><td>' + esc(b.rcptCount)
							+ '</td></tr>';
					}), BCK.t('No messages sent yet.'));
			}).catch(function () {
				bcList.innerHTML = '';
			});
		}
		loadBroadcasts();
		if (bcForm) {
			// occurrence filter — typed target ids from the loaded occurrence list
			BCK.api('/offerings/' + offeringId + '/slots').then(function (d) {
				var items = (d && (d.events || d.slots)) || [];
				var isSlot = !!(d && d.slots);
				var opts = '', seenSeries = {};
				items.forEach(function (e) {
					if (e.seriesId && !seenSeries[e.seriesId]) {
						seenSeries[e.seriesId] = true;
						opts += '<option value="series:' + parseInt(e.seriesId, 10) + '">'
							+ BCK.tf('Whole series #%s', [String(e.seriesId)]) + '</option>';
					}
					if (!e.seriesId) {
						var t = isSlot ? 'slot' : 'event';
						opts += '<option value="' + t + ':' + parseInt(e.id, 10) + '">'
							+ esc(dt(e.startsAt)) + '</option>';
					}
				});
				if (opts !== '' && bcOccSel && bcOccField) {
					bcOccSel.innerHTML += opts;
					bcOccField.hidden = false;
				}
			}).catch(fail);

			bcForm.addEventListener('submit', function (ev) {
				ev.preventDefault();
				var e1 = bcForm.querySelector('.bck-field-error');
				var fd = new FormData(bcForm);
				var occ = String(fd.get('occurrence') || '').split(':');
				var payload = {
					subject: String(fd.get('subject') || ''),
					body: String(fd.get('body') || '')
				};
				if (occ.length === 2) {
					payload.target_type = occ[0];
					payload.target_id = parseInt(occ[1], 10);
				}
				var btn = bcForm.querySelector('button[type="submit"]');
				if (btn) { btn.disabled = true; }
				BCK.api('/offerings/' + offeringId + '/broadcasts',
					{ method: 'POST', json: payload }).then(function (d2) {
						bcForm.reset(); err(e1, '');
						if (btn) { btn.disabled = false; }
						loadBroadcasts();
						BCK.toast('success', BCK.tf('Queued for %s recipients.', [String(d2 && d2.recipients || 0)]));
					}).catch(function (x) {
						if (btn) { btn.disabled = false; }
						err(e1, (x && x.message) || BCK.t('Could not send.'));
					});
			});
		}

		function loadTemplates() {
			BCK.api('/offerings/' + offeringId + '/slot-templates').then(function (d) {
				var tpls = (d && d.templates) || [];
				tplSelect.innerHTML = tpls.map(function (t) {
					return '<option value="' + t.id + '">'
						+ esc(t.durationMin + ' min · ' + t.capPerSlot + ' seats') + '</option>';
				}).join('');

				var card = document.getElementById('bck-tpl-card');
				if (card) { card.hidden = false; }
				var resSel = document.getElementById('bck-tpl-resource');
				if (resSel && resSel.options.length <= 1) {
					((d && d.resources) || []).forEach(function (r) {
						var op = document.createElement('option');
						op.value = String(r.id);
						op.textContent = r.name + ' (' + r.capacity + ')';
						resSel.appendChild(op);
					});
				}
				var resNames = {};
				((d && d.resources) || []).forEach(function (r) { resNames[r.id] = r.name; });
				var tplList = document.getElementById('bck-tpl-list');
				if (tplList) {
					tplList.innerHTML = tableBox(
						[BCK.t('Pattern'), BCK.t('Length'), BCK.t('Seats'), BCK.t('Pool'), ''],
						tpls.map(function (t) {
							return '<tr><td><code>' + esc(String(t.weeklyRrule || '').replace(/\r?\n/g, ' · '))
								+ '</code></td><td>' + t.durationMin + ' min</td><td>' + t.capPerSlot
								+ '</td><td>' + esc(resNames[t.resourceId] || '—') + '</td>'
								+ '<td><button class="bck-btn bck-btn--small" data-bck-del-tpl="'
								+ t.id + '">' + BCK.t('Delete') + '</button></td></tr>';
						}), BCK.t('No recurring patterns yet — add one below.'));
				}
			}).catch(fail);
		}

		var tplForm = document.getElementById('bck-tpl-form');
		if (tplForm) {
			tplForm.addEventListener('submit', function (ev) {
				ev.preventDefault();
				var e1 = tplForm.querySelector('.bck-field-error');
				var fd = new FormData(tplForm);
				var payload = {
					duration_min: String(fd.get('duration_min') || ''),
					cap_per_slot: String(fd.get('cap_per_slot') || ''),
					weekly_rrule: String(fd.get('weekly_rrule') || ''),
					horizon_days: String(fd.get('horizon_days') || '60'),
					lead_min: String(fd.get('lead_min') || '0')
				};
				if (fd.get('resource_id')) { payload.resource_id = String(fd.get('resource_id')); }
				BCK.api('/offerings/' + offeringId + '/slot-templates',
					{ method: 'POST', json: payload }).then(function (d) {
					tplForm.reset(); err(e1, '');
					loadTemplates(); loadOcc();
					BCK.toast('success', BCK.tf('%s slots created.', [String(d && d.materialized || 0)]));
				}).catch(function (x) {
					err(e1, (x && x.message) || BCK.t('Could not create the pattern.'));
				});
			});
		}

		var seriesChkEl = document.getElementById('bck-occ-series');
		if (seriesChkEl) {
			seriesChkEl.addEventListener('change', function () {
				document.getElementById('bck-occ-weeks-field').hidden = !seriesChkEl.checked;
			});
		}

		var occForm = document.getElementById('bck-occ-form');
		occForm.addEventListener('submit', function (ev) {
			ev.preventDefault();
			var e1 = occForm.querySelector('.bck-field-error');
			var fd = new FormData(occForm);
			var series = seriesChkEl && !seriesChkEl.closest('.bck-field').hidden && seriesChkEl.checked;
			var payload = {
				starts_at: fd.get('starts_at'), ends_at: fd.get('ends_at'),
				capacity: fd.get('capacity')
			};
			if (offering && offering.type === 'timeslot') { payload.tpl_id = fd.get('tpl_id'); }
			if (series) { payload.weeks = fd.get('weeks'); }
			var path = series ? '/offerings/' + offeringId + '/slot-series'
				: '/offerings/' + offeringId + '/slots';
			BCK.api(path, { method: 'POST', json: payload }).then(function () {
				occForm.reset(); err(e1, ''); loadOcc();
				BCK.toast('success', BCK.t('Added.'));
			}).catch(function (x) {
				err(e1, (x && x.message) || BCK.t('Could not add the date.'));
			});
		});

		// ---- sub-entity lists (pricecats / addons / intake / materials) ----
		function bindSection(listId, apiList, key, cols, rowFn, form, apiCreate, apiDelete, emptyMsg) {
			var listEl = document.getElementById(listId);
			function load() {
				BCK.api(apiList).then(function (d) {
					listEl.innerHTML = tableBox(cols, ((d && d[key]) || []).map(rowFn), emptyMsg);
				}).catch(fail);
			}
			load();
			if (form) {
				form.addEventListener('submit', function (ev) {
					ev.preventDefault();
					var e1 = form.querySelector('.bck-field-error');
					var payload = {};
					new FormData(form).forEach(function (v, k) { payload[k] = String(v); });
					BCK.api(apiCreate, { method: 'POST', json: payload }).then(function () {
						form.reset(); err(e1, ''); load();
						BCK.toast('success', BCK.t('Added.'));
					}).catch(function (x) {
						err(e1, (x && x.message) || BCK.t('Could not save.'));
					});
				});
			}
			return load;
		}

		bindSection('bck-pricecat-list',
			'/offerings/' + offeringId + '/price-categories', 'pricecats',
			[BCK.t('Name'), BCK.t('Price'), ''],
			function (p) {
				return '<tr><td>' + esc(p.name) + '</td><td>' + cents(p.priceCents) + '</td>'
					+ '<td><button class="bck-btn bck-btn--small" data-bck-delete="/price-categories/'
					+ p.id + '">' + BCK.t('Delete') + '</button></td></tr>';
			},
			document.getElementById('bck-pricecat-form'),
			'/offerings/' + offeringId + '/price-categories',
			'/price-categories/', BCK.t('No price categories yet.'));

		bindSection('bck-addon-list',
			'/offerings/' + offeringId + '/addons', 'addons',
			[BCK.t('Name'), BCK.t('Price'), ''],
			function (a) {
				return '<tr><td>' + esc(a.name) + '</td><td>' + cents(a.priceCents) + '</td>'
					+ '<td><button class="bck-btn bck-btn--small" data-bck-delete="/addons/'
					+ a.id + '">' + BCK.t('Delete') + '</button></td></tr>';
			},
			document.getElementById('bck-addon-form'),
			'/offerings/' + offeringId + '/addons',
			'/addons/', BCK.t('No add-ons yet.'));

		var intakeKindSel = document.getElementById('bck-iq-kind');
		if (intakeKindSel) {
			intakeKindSel.addEventListener('change', function () {
				document.getElementById('bck-iq-options-field').hidden =
					intakeKindSel.value !== 'select';
			});
		}
		bindSection('bck-intake-list',
			'/offerings/' + offeringId + '/intake', 'questions',
			[BCK.t('Question'), BCK.t('Type'), BCK.t('Required'), BCK.t('Visible to'), ''],
			function (q) {
				var del = parseInt(q.active, 10) === 1
					? '<button class="bck-btn bck-btn--small" data-bck-delete="/intake/'
						+ q.id + '">' + BCK.t('Remove') + '</button>'
					: '<span class="bck-badge bck-badge--info">' + BCK.t('inactive') + '</span>';
				return '<tr><td>' + esc(q.label) + '</td><td>' + esc(q.kind) + '</td><td>'
					+ (parseInt(q.required, 10) ? BCK.t('yes') : '—') + '</td><td>'
					+ esc(q.visibleTo) + '</td><td>' + del + '</td></tr>';
			},
			document.getElementById('bck-intake-form'),
			'/offerings/' + offeringId + '/intake',
			'/intake/', BCK.t('No questions yet.'));

		// materials need a real multipart upload — FormData, not JSON
		var matForm = document.getElementById('bck-material-form');
		var matList = document.getElementById('bck-material-list');
		function loadMaterials() {
			BCK.api('/offerings/' + offeringId + '/materials').then(function (d) {
				var items = (d && d.materials) || [];
				matList.innerHTML = tableBox([BCK.t('Title'), ''],
					items.map(function (m) {
						return '<tr><td>' + esc(m.title) + '</td>'
							+ '<td><button class="bck-btn bck-btn--small" data-bck-delete="/materials/'
							+ m.id + '">' + BCK.t('Delete') + '</button></td></tr>';
					}), BCK.t('No materials yet.'));
			}).catch(fail);
		}
		loadMaterials();
		matForm.addEventListener('submit', function (ev) {
			ev.preventDefault();
			var e1 = matForm.querySelector('.bck-field-error');
			var fd = new FormData(matForm);
			if (!fd.get('file') || !fd.get('file').size) {
				err(e1, BCK.t('Choose a file.'));
				return;
			}
			BCK.api('/offerings/' + offeringId + '/materials',
				{ method: 'POST', formData: fd }).then(function () {
				matForm.reset(); err(e1, ''); loadMaterials();
				BCK.toast('success', BCK.t('Uploaded.'));
			}).catch(function (x) {
				err(e1, (x && x.message) || BCK.t('Upload failed.'));
			});
		});

		// shared delete/edit dispatch (occ + sub-entities)
		document.addEventListener('click', function (ev) {
			var editBtn = ev.target.closest('[data-bck-edit-occ]');
			if (editBtn) {
				var occId = editBtn.getAttribute('data-bck-edit-occ');
				var body = BCK.el('div', {});
				body.appendChild(field(BCK.t('Starts'),
					input('occ_starts', { type: 'datetime-local', required: 'required' })));
				body.appendChild(field(BCK.t('Ends'),
					input('occ_ends', { type: 'datetime-local', required: 'required' })));
				body.appendChild(field(BCK.t('Seats'),
					input('occ_cap', { type: 'number', min: '1', max: '100000',
						inputmode: 'numeric', required: 'required' }),
					BCK.t('Cannot go below the seats already sold.')));
				body.querySelector('#bck-dlg-occ_starts').value =
					String(editBtn.getAttribute('data-starts') || '').slice(0, 16);
				body.querySelector('#bck-dlg-occ_ends').value =
					String(editBtn.getAttribute('data-ends') || '').slice(0, 16);
				body.querySelector('#bck-dlg-occ_cap').value =
					editBtn.getAttribute('data-cap') || '';
				BCK.openDialog({
					title: BCK.t('Edit date'),
					body: body,
					submitLabel: BCK.t('Save'),
					onSubmit: function (fd) {
						return BCK.api('/slots/' + occId, {
							method: 'PUT',
							json: {
								starts_at: fd.get('occ_starts'),
								ends_at: fd.get('occ_ends'),
								capacity: fd.get('occ_cap')
							}
						}).then(function () { loadOcc(); });
					}
				});
				return;
			}
			var occBtn = ev.target.closest('[data-bck-del-occ]');
			if (occBtn) {
				occBtn.disabled = true;
				BCK.api('/slots/' + occBtn.getAttribute('data-bck-del-occ'), { method: 'DELETE' })
					.then(loadOcc).catch(function (e) { fail(e); occBtn.disabled = false; });
				return;
			}
			var tplBtn = ev.target.closest('[data-bck-del-tpl]');
			if (tplBtn) {
				tplBtn.disabled = true;
				BCK.api('/slot-templates/' + tplBtn.getAttribute('data-bck-del-tpl'),
					{ method: 'DELETE' })
					.then(function () { loadTemplates(); loadOcc(); })
					.catch(function (e) { fail(e); tplBtn.disabled = false; });
				return;
			}
		});

		// header Edit button
		var editBtn = document.getElementById('bck-edit-offering');
		if (editBtn) {
			editBtn.addEventListener('click', function () {
				window.location.href = BCK.appBase() + '/offerings/' + offeringId + '/edit';
			});
		}
	}
}());

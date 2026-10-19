/* Admin pages — BCK.api contract: {json:} in, resolved data out, ApiError on failure. */
(function () {
	'use strict';
	if (!window.BCK) { return; }

	function reload() { window.location.reload(); }
	function fail(e) { BCK.handleError(e); }

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
		document.addEventListener('click', function (ev) {
			var btn = ev.target.closest('[data-bck-order-action]');
			if (!btn) { return; }
			var action = btn.getAttribute('data-bck-order-action');
			btn.disabled = true;
			var payload = {};
			if (action === 'refund') {
				var cents = window.prompt('Refund amount in cents (empty = full):');
				if (cents !== null && cents !== '') { payload.amount_cents = parseInt(cents, 10); }
			}
			BCK.api('/orders/' + oid + '/' + action, { method: 'POST', json: payload })
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
			var id = picker.value;
			if (!id) { return; }
			BCK.api('/slots/' + id + '/roster', { method: 'GET' })
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
						html += '<tr><td>' + BCK.esc(t.attendee_name || '') + '</td>'
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

	// check-in / undo (roster + scan share this)
	document.addEventListener('click', function (ev) {
		var btn = ev.target.closest('[data-bck-checkin]');
		if (!btn) { return; }
		var undo = btn.getAttribute('data-bck-undo') === '1';
		btn.disabled = true;
		BCK.api('/attendees/' + btn.getAttribute('data-bck-checkin') + '/check-in',
			{ method: undo ? 'DELETE' : 'POST' })
			.then(reload)
			.catch(function (e) { fail(e); btn.disabled = false; });
	});

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
					scanResult.className = 'bck-callout bck-callout--' + (d.checked_in ? 'warning' : 'success');
					scanResult.innerHTML = '';
					var p = document.createElement('p');
					p.className = 'bck-callout__text';
					p.textContent = (d.attendee_name || 'Ticket') + ' — ' + (d.checked_in ? 'already checked in' : 'valid');
					scanResult.appendChild(p);
					if (!d.checked_in) {
						var btn = document.createElement('button');
						btn.type = 'button';
						btn.className = 'bck-btn bck-btn--primary';
						btn.setAttribute('data-bck-checkin', String(d.ticket_id));
						btn.textContent = 'Check in';
						scanResult.appendChild(btn);
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
}());

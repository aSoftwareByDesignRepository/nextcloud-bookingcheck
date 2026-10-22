/* Public pages — waitlist join + verified reviews + promo validate
 * (same pubApi shape). Waitlist answers are intentionally uniform:
 * the server never reveals whether a join actually registered. */
(function () {
	'use strict';
	if (!window.BCK) { return; }
	function pubApi(path, payload) {
		return fetch(BCK.appBase() + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
			body: JSON.stringify(payload)
		}).then(function (r) { return r.json().catch(function () { return {}; }); });
	}

	/* --- cancel booking: destructive — never fire it on a stray tap --- */
	document.querySelectorAll('form[data-bck-cancel-confirm]').forEach(function (form) {
		form.addEventListener('submit', function (ev) {
			if (!BCK.openDialog) { return; } // no dialog lib → plain submit
			ev.preventDefault();
			var wrap = BCK.el('div', { class: 'bck-field' });
			wrap.appendChild(BCK.el('p', {
				text: BCK.t('This cancels the whole booking — tickets are revoked and cannot be restored.')
			}));
			BCK.openDialog({
				title: BCK.t('Cancel this booking?'),
				body: wrap,
				submitLabel: BCK.t('Yes, cancel booking'),
				danger: true,
				onSubmit: function () {
					// the endpoint answers JSON, so a bare form POST would
					// strand the buyer on a raw payload — fetch + reload
					// lands them back on the (now cancelled) booking
					return fetch(form.action, {
						method: 'POST', credentials: 'same-origin',
						headers: { 'Accept': 'application/json' }
					}).then(function (r) { return r.json().catch(function () { return {}; }); })
						.then(function (b) {
							if (b && b.success) { window.location.reload(); return; }
							throw new Error((b && b.message) || BCK.t('Could not cancel this booking.'));
						});
				}
			});
		});
	});

	/* --- promo validate (checkout page) --- */
	var applyBtn = document.getElementById('bck-promo-apply');
	if (applyBtn) {
		applyBtn.addEventListener('click', function () {
			var code = document.getElementById('bck-promo');
			var hint = document.getElementById('bck-promo-hint');
			if (!code || !hint) { return; }
			pubApi('/pub/api/promo/validate', { code: code.value }).then(function (b) {
				hint.textContent = (b && b.success && b.data && b.data.message)
					? b.data.message
					: BCK.t('Code not valid for this booking.');
			});
		});
	}

	/* --- waitlist join (offering page, full+waitlist occurrences) --- */
	document.querySelectorAll('.bck-waitlist-join').forEach(function (form) {
		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			var email = form.querySelector('input[name="email"]');
			var msg = form.querySelector('.bck-waitlist-msg');
			var btn = form.querySelector('button[type="submit"]');
			if (!email || !email.value) { return; }
			if (btn) { btn.disabled = true; }
			pubApi('/pub/api/waitlist', {
				target_type: form.getAttribute('data-target-type'),
				target_id: parseInt(form.getAttribute('data-target-id'), 10),
				email: email.value
			}).then(function (res) {
				// 202 on every business outcome — but a 400 means the input
				// itself was rejected; surface the field error, don't claim
				// a join that never happened.
				if (res && res.success === false) {
					if (btn) { btn.disabled = false; }
					if (msg) {
						msg.hidden = false;
						msg.textContent = (res.fields && res.fields.email) ||
							res.message || BCK.t('Something went wrong — please try again.');
					}
					return;
				}
				// 202 always — same answer for join, dedupe and invalid target
				form.innerHTML = '<p class="bck-waitlist-msg">' +
					BCK.t("Done — we'll email you if a seat frees up.") + '</p>';
			}).catch(function () {
				if (btn) { btn.disabled = false; }
				if (msg) { msg.hidden = false; msg.textContent = BCK.t('Could not reach the server — try again.'); }
			});
		});
	});

	/* --- per-attendee intake answers (manage page, paid orders) --- */
	document.querySelectorAll('[data-bck-intake-form]').forEach(function (form) {
		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			var btn = form.querySelector('button[type="submit"]');
			var saved = form.querySelector('[data-intake-saved]');
			var answers = {};
			form.querySelectorAll('[name^="q"]').forEach(function (el) {
				var key = el.name.slice(1);
				if (el.type === 'checkbox') { answers[key] = el.checked ? '1' : '0'; }
				else { answers[key] = el.value; }
			});
			form.querySelectorAll('.bck-field-error').forEach(function (e) { e.hidden = true; });
			form.querySelectorAll('[aria-invalid]').forEach(function (e) { e.removeAttribute('aria-invalid'); });
			if (btn) { btn.disabled = true; }
			pubApi('/pub/m/' + encodeURIComponent(form.getAttribute('data-manage-token')) + '/answers', {
				ticket_id: parseInt(form.getAttribute('data-ticket-id'), 10),
				answers: answers
			}).then(function (b) {
				if (b && b.success) {
					if (saved) { saved.hidden = false; }
					if (btn) { btn.disabled = false; }
					return;
				}
				if (btn) { btn.disabled = false; }
				var fields = (b && b.fields) || {};
				var any = false;
				Object.keys(fields).forEach(function (k) {
					var slot = form.querySelector('[data-err="' + k + '"]');
					if (slot) {
						any = true;
						slot.hidden = false;
						slot.textContent = fields[k];
						var input = form.querySelector('[name="' + k + '"]');
						if (input) { input.setAttribute('aria-invalid', 'true'); }
					}
				});
				if (!any && saved) {
					saved.hidden = false;
					saved.textContent = (b && b.message) || BCK.t('Could not save your answers.');
				}
			}).catch(function () {
				if (btn) { btn.disabled = false; }
				if (saved) {
					saved.hidden = false;
					saved.textContent = BCK.t('Could not reach the server — try again.');
				}
			});
		});
	});

	/* --- attendee uploads (manage page; multipart — NOT pubApi/JSON) --- */
	document.querySelectorAll('.bck-upload-form').forEach(function (form) {
		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			var btn = form.querySelector('button[type="submit"]');
			var err = form.querySelector('.bck-field-error');
			var token = form.getAttribute('data-manage-token');
			if (btn) { btn.disabled = true; }
			fetch(BCK.appBase() + '/pub/m/' + encodeURIComponent(token) + '/uploads', {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Accept': 'application/json' },
				body: new FormData(form)
			}).then(function (r) { return r.json().catch(function () { return {}; }); })
				.then(function (b) {
					if (b && b.success) {
						window.location.reload(); // list + quota re-render server-side
						return;
					}
					if (btn) { btn.disabled = false; }
					if (err) {
						err.hidden = false;
						var fields = (b && b.fields) || {};
						err.textContent = fields.file || fields.display_name || (b && b.message)
							|| BCK.t('The file could not be uploaded.');
					}
				}).catch(function () {
					if (btn) { btn.disabled = false; }
					if (err) { err.hidden = false; err.textContent = BCK.t('Could not reach the server — try again.'); }
				});
		});
	});
	document.querySelectorAll('.bck-upload-del').forEach(function (btn) {
		btn.addEventListener('click', function () {
			btn.disabled = true;
			var token = btn.getAttribute('data-manage-token');
			var id = btn.getAttribute('data-upload-id');
			fetch(BCK.appBase() + '/pub/m/' + encodeURIComponent(token) + '/uploads/' + encodeURIComponent(id), {
				method: 'DELETE', credentials: 'same-origin',
				headers: { 'Accept': 'application/json' }
			}).then(function (r) { return r.json().catch(function () { return {}; }); })
				.then(function (b) {
					if (b && b.success) {
						var li = btn.closest('.bck-upload-item');
						if (li) { li.remove(); }
					} else {
						btn.disabled = false;
					}
				}).catch(function () { btn.disabled = false; });
		});
	});

	document.querySelectorAll('.bck-upload-report').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var token = btn.getAttribute('data-manage-token');
			var id = btn.getAttribute('data-upload-id');
			var reason = '';
			if (BCK.openDialog) {
				var wrap = BCK.el('div', { class: 'bck-field' });
				var ta = document.createElement('textarea');
				ta.maxLength = 500; ta.rows = 3; ta.className = 'bck-input';
				ta.setAttribute('aria-label', BCK.t('Why are you reporting this file?'));
				wrap.appendChild(ta);
				BCK.openDialog({
					title: BCK.t('Report this file?'),
					body: wrap,
					submitLabel: BCK.t('Report'),
					onSubmit: function () {
						return fetch(BCK.appBase() + '/pub/m/' + encodeURIComponent(token)
								+ '/uploads/' + encodeURIComponent(id) + '/report', {
							method: 'POST', credentials: 'same-origin',
							headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
							body: JSON.stringify({ reason: ta.value })
						}).then(function (r) { return r.json(); })
							.then(function (b) {
								if (b && b.success) {
									btn.disabled = true;
									btn.textContent = BCK.t('Reported');
								}
							});
					}
				});
			}
		});
	});

	/* --- verified review (manage page, paid orders) --- */
	document.querySelectorAll('.bck-review-form').forEach(function (form) {
		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			var btn = form.querySelector('button[type="submit"]');
			var err = form.querySelector('.bck-field-error');
			if (btn) { btn.disabled = true; }
			pubApi('/pub/api/reviews', {
				manage_token: form.getAttribute('data-manage-token'),
				line_id: parseInt(form.getAttribute('data-line-id'), 10),
				rating: parseInt(form.rating ? form.rating.value : '0', 10),
				text: form.text ? form.text.value : ''
			}).then(function (b) {
				if (b && b.success) {
					form.innerHTML = '<p class="bck-waitlist-msg">' +
						BCK.t('Thanks — your review awaits moderation.') + '</p>';
				} else {
					if (btn) { btn.disabled = false; }
					if (err) { err.hidden = false; err.textContent = (b && b.message) || BCK.t('Could not save your review.'); }
				}
			}).catch(function () {
				if (btn) { btn.disabled = false; }
				if (err) { err.hidden = false; err.textContent = BCK.t('Could not reach the server — try again.'); }
			});
		});
	});
}());

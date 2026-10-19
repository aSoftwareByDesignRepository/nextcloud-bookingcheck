/* Public checkout — submit → PSP redirect or done. Public API lives at
 * appBase + '/pub/api/...' (not under the admin /api base). */
(function () {
	'use strict';
	var form = document.getElementById('bck-checkout');
	if (!form || !window.BCK) { return; }
	var slug = form.getAttribute('data-bck-slug');
	var approval = form.getAttribute('data-bck-approval') === '1';
	var submitBtn = document.getElementById('bck-pay-btn');

	function pubApi(path, payload) {
		return fetch(BCK.appBase() + path, {
			method: payload ? 'POST' : 'GET',
			credentials: 'same-origin',
			headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
			body: payload ? JSON.stringify(payload) : undefined
		}).then(function (r) { return r.json().catch(function () { return {}; }); });
	}

	form.addEventListener('submit', function (ev) {
		ev.preventDefault();
		submitBtn.disabled = true;
		var fd = new FormData(form);
		pubApi('/pub/api/orders', {
			slug: slug,
			qty: parseInt(fd.get('qty'), 10) || 1,
			buyer_name: String(fd.get('name') || ''),
			buyer_email: String(fd.get('email') || ''),
			code: String(fd.get('code') || '')
		}).then(function (body) {
			if (body && body.success && body.data) {
				if (approval || body.data.status === 'paid' || body.data.state === 'paid') {
					window.location.href = body.data.manage_url;
				} else if (body.data.pay_url) {
					window.location.href = body.data.pay_url;
				}
				return;
			}
			submitBtn.disabled = false;
			if (body && body.fields) {
				Object.keys(body.fields).forEach(function (k) {
					var input = form.querySelector('[name="' + k + '"]');
					var err = document.getElementById('bck-' + k + '-error');
					if (input) { input.setAttribute('aria-invalid', 'true'); }
					if (err) { err.textContent = body.fields[k]; err.hidden = false; }
				});
			}
		}).catch(function () {
			submitBtn.disabled = false;
		});
	});
}());

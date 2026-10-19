/* Portal request — email → code. Two-step, never reveals existence. */
(function () {
	'use strict';
	var req = document.getElementById('bck-portal-request');
	var ver = document.getElementById('bck-portal-verify');
	if (!req || !ver || !window.BCK) { return; }
	var email = '';

	function pubApi(path, payload) {
		return fetch(BCK.appBase() + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
			body: JSON.stringify(payload)
		}).then(function (r) { return r.json().catch(function () { return {}; }); });
	}

	req.addEventListener('submit', function (ev) {
		ev.preventDefault();
		email = String(new FormData(req).get('email') || '');
		pubApi('/pub/api/portal/send', { email: email }).then(function () {
			// always advance — existence stays hidden either way
			req.hidden = true;
			ver.hidden = false;
			var code = document.getElementById('bck-portal-code');
			if (code) { code.focus(); }
		});
	});

	ver.addEventListener('submit', function (ev) {
		ev.preventDefault();
		var code = String(new FormData(ver).get('code') || '');
		pubApi('/pub/api/portal/verify', { email: email, code: code }).then(function (b) {
			if (b && b.success) {
				window.location.href = BCK.appBase() + '/pub/portal/home';
				return;
			}
			var err = document.getElementById('bck-portal-error');
			if (err) {
				err.textContent = 'That code is not right — try again.';
				err.hidden = false;
			}
		});
	});
}());

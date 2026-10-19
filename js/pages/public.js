/* Public pages — waitlist join + promo validate (same pubApi shape). */
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
	var applyBtn = document.getElementById('bck-promo-apply');
	if (applyBtn) {
		applyBtn.addEventListener('click', function () {
			var code = document.getElementById('bck-promo');
			var hint = document.getElementById('bck-promo-hint');
			if (!code || !hint) { return; }
			pubApi('/pub/api/promo/validate', { code: code.value }).then(function (b) {
				hint.textContent = (b && b.success && b.data && b.data.message)
					? b.data.message
					: 'Code not valid for this booking.';
			});
		});
	}
}());

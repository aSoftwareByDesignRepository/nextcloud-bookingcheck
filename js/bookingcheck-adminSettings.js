/* Admin settings — per-section PUTs matching the SECTIONS map server-side. */
(function () {
	'use strict';
	var btn = document.getElementById('bck-settings-save');
	var status = document.getElementById('bck-settings-status');
	if (!btn || !window.BCK) { return; }

	function val(id) {
		var el = document.getElementById(id);
		return el ? el.value : '';
	}
	function checked(id) {
		var el = document.getElementById(id);
		return el && el.checked ? '1' : '0';
	}

	btn.addEventListener('click', function () {
		btn.disabled = true;
		status.textContent = '';
		var calls = [
			BCK.api('/settings/payments', { method: 'PUT', json: {
				psp_provider: val('bck-psp-provider'),
				mollie_api_key: val('bck-mollie-key'),
				stripe_secret_key: val('bck-stripe-key')
			}}),
			BCK.api('/settings/invoicing', { method: 'PUT', json: {
				seller_name: val('bck-company-name'),
				seller_address: val('bck-company-address'),
				seller_vat_id: val('bck-company-vat')
			}}),
			BCK.api('/settings/directory', { method: 'PUT', json: {
				directory_enabled: checked('bck-directory'),
				cors_origins: val('bck-cors')
			}}),
			BCK.api('/settings/general', { method: 'PUT', json: {
				portal_enabled: checked('bck-portal-enabled'),
				license_key: val('bck-license-key')
			}})
		];
		Promise.all(calls).then(function () {
			status.textContent = 'Saved.';
			btn.disabled = false;
		}).catch(function (e) {
			status.textContent = (e && e.message) || 'Save failed.';
			btn.disabled = false;
		});
	});
}());

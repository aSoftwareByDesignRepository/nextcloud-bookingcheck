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
				mollie_test_key: val('bck-mollie-test'),
				mollie_webhook_secret: val('bck-mollie-webhook'),
				stripe_secret_key: val('bck-stripe-key'),
				stripe_webhook_secret: val('bck-stripe-webhook'),
				sumup_api_key: val('bck-sumup-key'),
				sumup_merchant_code: val('bck-sumup-merchant'),
				paypal_client_id: val('bck-paypal-id'),
				paypal_client_secret: val('bck-paypal-secret'),
				paypal_sandbox: checked('bck-paypal-sandbox'),
				klarna_api_uid: val('bck-klarna-uid'),
				klarna_api_password: val('bck-klarna-secret'),
				klarna_sandbox: checked('bck-klarna-sandbox')
			}}),
			BCK.api('/settings/invoicing', { method: 'PUT', json: {
				seller_name: val('bck-company-name'),
				seller_address: val('bck-company-address'),
				seller_vat_id: val('bck-company-vat'),
				seller_country: val('bck-company-country'),
				kleinunternehmer: checked('bck-kleinunternehmer'),
				vies_online: checked('bck-vies-online'),
				oss_enabled: checked('bck-oss'),
				oss_vat_map: val('bck-oss-map'),
				datev_consultant_no: val('bck-datev-consultant'),
				datev_client_no: val('bck-datev-client'),
				datev_revenue_account: val('bck-datev-revenue'),
				datev_receivable_account: val('bck-datev-receivable')
			}}),
			BCK.api('/settings/flows', { method: 'PUT', json: {
				order_hold_min: val('bck-order-hold'),
				approval_hold_min: val('bck-approval-hold'),
				payment_ttl_min: val('bck-payment-ttl'),
				otp_ttl_min: val('bck-otp-ttl'),
				portal_sess_days: val('bck-portal-sess'),
				waitlist_offer_h: val('bck-waitlist-offer')
			}}),
			BCK.api('/settings/directory', { method: 'PUT', json: {
				directory_enabled: checked('bck-directory'),
				cors_origins: val('bck-cors'),
				default_locale: val('bck-default-locale'),
				ical_feed_token: val('bck-ical-token')
			}}),
			BCK.api('/settings/integrations', { method: 'PUT', json: {
				av_enabled: checked('bck-av'),
				av_icap_endpoint: val('bck-av-endpoint'),
				wallet_google_issuer: val('bck-wallet-google-issuer'),
				wallet_google_sa_email: val('bck-wallet-google-sa'),
				wallet_google_secret: val('bck-wallet-google-secret'),
				wallet_apple_pass_type_id: val('bck-wallet-apple-ptid'),
				wallet_apple_team_id: val('bck-wallet-apple-team'),
				wallet_apple_cert_pem: val('bck-wallet-apple-cert'),
				wallet_apple_key_pem: val('bck-wallet-apple-key')
			}}),
			BCK.api('/settings/general', { method: 'PUT', json: {
				portal_enabled: checked('bck-portal-enabled'),
				license_key: val('bck-license-key')
			}}),
			BCK.api('/settings/notify-paid', { method: 'POST', json: {
				enabled: checked('bck-notify-paid')
			}})
		];
		if (document.getElementById('bck-crm-sync')) {
			calls.push(BCK.api('/settings/integrations', { method: 'PUT', json: {
				crm_sync_enabled: checked('bck-crm-sync'),
				crm_sync_token: val('bck-crm-token')
			}}));
		}
		// NOTE: the crm PUT is partial — the server only writes keys present
		// in the body, so the wallet/AV PUT above is untouched by it.
		Promise.all(calls).then(function (results) {
			var lic = null;
			(results || []).forEach(function (r) { if (r && r.license) { lic = r.license; } });
			if (lic && val('bck-license-key') !== '' && lic.state !== 'pro') {
				status.textContent = lic.state === 'expired'
					? 'Saved — but this license key is expired.'
					: 'Saved — but this license key is not valid.';
			} else {
				status.textContent = lic && lic.state === 'pro' ? 'Saved — Pro license active.' : 'Saved.';
			}
			btn.disabled = false;
		}).catch(function (e) {
			status.textContent = (e && e.message) || 'Save failed.';
			btn.disabled = false;
		});
	});

	/* NH-37 mail-template overrides — own save/delete flow, page reloads
	   so the saved list re-renders server-side. */
	var mtSave = document.getElementById('bck-mailtpl-save');
	var mtStatus = document.getElementById('bck-mailtpl-status');
	if (mtSave) {
		mtSave.addEventListener('click', function () {
			mtSave.disabled = true;
			mtStatus.textContent = '';
			BCK.api('/settings/mailtpl', { method: 'PUT', json: {
				kind: val('bck-mailtpl-kind'),
				locale: val('bck-mailtpl-locale'),
				subject: val('bck-mailtpl-subject'),
				extra_text: val('bck-mailtpl-extra')
			}}).then(function () {
				window.location.reload();
			}).catch(function (e) {
				mtStatus.textContent = (e && e.message) || 'Save failed.';
				mtSave.disabled = false;
			});
		});
	}
	var mtList = document.getElementById('bck-mailtpl-list');
	if (mtList) {
		mtList.addEventListener('click', function (ev) {
			var b = ev.target.closest('.bck-mailtpl-del');
			if (!b) { return; }
			b.disabled = true;
			BCK.api('/settings/mailtpl/' + encodeURIComponent(b.dataset.id), { method: 'DELETE' })
				.then(function () { window.location.reload(); })
				.catch(function () { b.disabled = false; });
		});
	}
}());

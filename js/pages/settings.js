/* In-app settings — per-section PUTs matching SettingsController::SECTIONS.
   Each settings page carries one or more forms; a form's data-section
   declares which API section it saves. */
(function () {
	'use strict';
	var root = document.getElementById('bck-settings-root');
	if (!root || !window.BCK) { return; }

	function val(id) {
		var el = document.getElementById(id);
		return el ? el.value : '';
	}
	function checked(id) {
		var el = document.getElementById(id);
		return el && el.checked ? '1' : '0';
	}
	function t(s) { return BCK.t(s); }

	/* [key, elementId, kind] — kind 'c' reads .checked as '1'/'0'. */
	var FIELD_MAP = {
		payments: [
			['psp_provider', 'bck-psp-provider'],
			['mollie_api_key', 'bck-mollie-key'],
			['mollie_test_key', 'bck-mollie-test'],
			['mollie_webhook_secret', 'bck-mollie-webhook'],
			['stripe_secret_key', 'bck-stripe-key'],
			['stripe_webhook_secret', 'bck-stripe-webhook'],
			['sumup_api_key', 'bck-sumup-key'],
			['sumup_merchant_code', 'bck-sumup-merchant'],
			['paypal_client_id', 'bck-paypal-id'],
			['paypal_client_secret', 'bck-paypal-secret'],
			['paypal_sandbox', 'bck-paypal-sandbox', 'c'],
			['klarna_api_uid', 'bck-klarna-uid'],
			['klarna_api_password', 'bck-klarna-secret'],
			['klarna_sandbox', 'bck-klarna-sandbox', 'c']
		],
		invoicing: [
			['seller_name', 'bck-company-name'],
			['seller_email', 'bck-seller-email'],
			['invoice_prefix', 'bck-invoice-prefix'],
			['seller_address', 'bck-company-address'],
			['seller_vat_id', 'bck-company-vat'],
			['seller_country', 'bck-company-country'],
			['kleinunternehmer', 'bck-kleinunternehmer', 'c'],
			['vies_online', 'bck-vies-online', 'c'],
			['oss_enabled', 'bck-oss', 'c'],
			['oss_vat_map', 'bck-oss-map'],
			['datev_consultant_no', 'bck-datev-consultant'],
			['datev_client_no', 'bck-datev-client'],
			['datev_revenue_account', 'bck-datev-revenue'],
			['datev_receivable_account', 'bck-datev-receivable']
		],
		flows: [
			['order_hold_min', 'bck-order-hold'],
			['approval_hold_min', 'bck-approval-hold'],
			['payment_ttl_min', 'bck-payment-ttl'],
			['otp_ttl_min', 'bck-otp-ttl'],
			['portal_sess_days', 'bck-portal-sess'],
			['waitlist_offer_h', 'bck-waitlist-offer']
		],
		directory: [
			['directory_enabled', 'bck-directory', 'c'],
			['cors_origins', 'bck-cors'],
			['default_locale', 'bck-default-locale'],
			['ical_feed_token', 'bck-ical-token']
		],
		integrations: [
			['av_enabled', 'bck-av', 'c'],
			['av_icap_endpoint', 'bck-av-endpoint'],
			['wallet_google_issuer', 'bck-wallet-google-issuer'],
			['wallet_google_sa_email', 'bck-wallet-google-sa'],
			['wallet_google_secret', 'bck-wallet-google-secret'],
			['wallet_apple_pass_type_id', 'bck-wallet-apple-ptid'],
			['wallet_apple_team_id', 'bck-wallet-apple-team'],
			['wallet_apple_cert_pem', 'bck-wallet-apple-cert'],
			['wallet_apple_key_pem', 'bck-wallet-apple-key'],
			['crm_sync_enabled', 'bck-crm-sync', 'c'],
			['crm_sync_token', 'bck-crm-token']
		],
		general: [
			['portal_enabled', 'bck-portal-enabled', 'c'],
			['license_key', 'bck-license-key']
		]
	};

	function collect(apiSection) {
		var out = {};
		(FIELD_MAP[apiSection] || []).forEach(function (f) {
			var el = document.getElementById(f[1]);
			if (!el) { return; } // key absent on this page — skip cleanly
			out[f[0]] = f[2] === 'c' ? (el.checked ? '1' : '0') : el.value;
		});
		return out;
	}

	function showSaved(form, data) {
		var status = form.querySelector('.bck-settings-status');
		if (!status) { return; }
		var lic = data && data.license;
		if (lic && val('bck-license-key') !== '' && lic.state !== 'pro') {
			status.textContent = lic.state === 'expired'
				? t('Saved — but this license key is expired.')
				: t('Saved — but this license key is not valid.');
		} else {
			status.textContent = lic && lic.state === 'pro'
				? t('Saved — Pro license active.')
				: t('Saved.');
		}
	}

	function showFailed(form, e) {
		var status = form.querySelector('.bck-settings-status');
		if (status) {
			status.textContent = (e && e.message) || t('Save failed.');
		}
		if (e && e.fields && window.CheckFieldErrors) {
			CheckFieldErrors.markValidationFields(e.fields);
		}
	}

	/* Page section → list of [apiSection, payloadFn]. */
	var PLANS = {
		payments: [['payments', function () { return collect('payments'); }]],
		invoicing: [['invoicing', function () { return collect('invoicing'); }]],
		flow: [['flows', function () { return collect('flows'); }]],
		portal: [
			['directory', function () { return collect('directory'); }],
			['general', function () { return collect('general'); }]
		],
		integrations: [['integrations', function () { return collect('integrations'); }]],
		license: [['general', function () { return { license_key: val('bck-license-key') }; }]],
		emails: [],
		access: []
	};

	/* ---------- access section: door mode + directory pickers ---------- */
	var accessState = { admins: [], users: [] };

	function bindUserPicker(prefix, stateKey) {
		var input = document.getElementById(prefix + '-search');
		var results = document.getElementById(prefix + '-results');
		var chips = document.getElementById(prefix + '-chips');
		if (!input || !results || !chips || !BCK.initPicker) { return; }
		var pre = [];
		var form = input.closest('form');
		try {
			pre = JSON.parse(form.getAttribute(stateKey === 'admins' ? 'data-app-admins' : 'data-allowed-users') || '[]');
		} catch (e) { pre = []; }
		BCK.initPicker({
			input: input,
			results: results,
			chips: chips,
			selected: pre.map(function (u) { return { id: u.id, label: u.displayName || u.id }; }),
			search: function (term, signal) {
				return BCK.api('/settings/directory/users?q=' + encodeURIComponent(term), { signal: signal })
					.then(function (d) {
						return (d.users || []).map(function (u) {
							return { id: u.id, label: u.displayName || u.id };
						});
					});
			},
			onChange: function (ids) { accessState[stateKey] = ids; }
		});
		accessState[stateKey] = pre.map(function (u) { return u.id; });
	}

	function saveAccess(form) {
		var mode = document.getElementById('bck-access-mode');
		var open = mode && mode.value === 'open';
		var confirmOpen = document.getElementById('bck-access-confirm-open');
		if (open && !(confirmOpen && confirmOpen.checked)) {
			if (window.CheckFieldErrors) {
				CheckFieldErrors.markValidationFields({ 'bck-access-confirm-open': t('Confirm open access first.') });
			}
			return Promise.reject({ silent: true, message: t('Confirm open access before saving.') });
		}
		var gids = [];
		form.querySelectorAll('input[name="access_allowed_groups"]:checked').forEach(function (cb) {
			gids.push(cb.value);
		});
		return BCK.api('/settings/access', { method: 'PUT', json: {
			access_restriction_enabled: open ? '0' : '1',
			access_app_admin_user_ids: JSON.stringify(accessState.admins),
			access_allowed_user_ids: JSON.stringify(accessState.users),
			access_allowed_group_ids: JSON.stringify(gids)
		}});
	}

	root.querySelectorAll('.bck-settings-form').forEach(function (form) {
		var section = form.getAttribute('data-section');
		if (section === 'access') {
			bindUserPicker('bck-app-admins', 'admins');
			bindUserPicker('bck-allowed-users', 'users');
			var mode = document.getElementById('bck-access-mode');
			var wrap = document.getElementById('bck-access-open-confirm-wrap');
			if (mode && wrap) {
				mode.addEventListener('change', function () {
					wrap.hidden = mode.value !== 'open';
				});
			}
		}
		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			var btn = form.querySelector('button[type="submit"]');
			if (btn) { btn.disabled = true; }
			var done = function () { if (btn) { btn.disabled = false; } };
			var save;
			if (section === 'access') {
				save = saveAccess(form);
			} else {
				var calls = (PLANS[section] || []).map(function (step) {
					return BCK.api('/settings/' + step[0], { method: 'PUT', json: step[1]() });
				});
				if (section === 'emails') {
					calls.push(BCK.api('/settings/notify-paid', { method: 'POST',
						json: { enabled: checked('bck-notify-paid') } }));
				}
				save = Promise.all(calls);
			}
			save.then(function (results) {
				var lic = null;
				(Array.isArray(results) ? results : [results]).forEach(function (r) {
					if (r && r.license) { lic = r.license; }
				});
				showSaved(form, { license: lic });
				done();
			}).catch(function (e) {
				if (e && e.silent) {
					var status = form.querySelector('.bck-settings-status');
					if (status) { status.textContent = e.message; }
				} else {
					showFailed(form, e);
				}
				done();
			});
		});
	});

	/* ---------- NH-37 mail-template overrides (emails page) ---------- */
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
				mtStatus.textContent = (e && e.message) || t('Save failed.');
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

	/* ---------- device tokens (integrations page) ---------- */
	var devCard = document.getElementById('bck-devices-card');
	var devList = document.getElementById('bck-device-list');
	// Free tier: the card renders locked (all device APIs are Pro-gated) —
	// skip the list fetch that can only 403 and the dead form bindings.
	if (devList && devCard && devCard.getAttribute('data-companion-locked') !== '1') {
		var devStatus = document.getElementById('bck-device-status');
		var devNew = document.getElementById('bck-device-new');
		var devToken = document.getElementById('bck-device-token');
		var ROLE_LABELS = { door: 'door', manager: 'manager', api: 'api' };

		function scopeLabel(offeringIds) {
			if (!offeringIds) { return t('all offerings'); }
			var ids;
			try { ids = JSON.parse(offeringIds); } catch (e) { ids = null; }
			return (ids && ids.length) ? ('#' + ids.join(', #')) : t('all offerings');
		}

		function renderDevices(devices) {
			devList.textContent = '';
			if (!devices.length) {
				var empty = document.createElement('li');
				empty.className = 'bck-device-item';
				empty.textContent = t('No devices paired yet.');
				devList.appendChild(empty);
				return;
			}
			devices.forEach(function (d) {
				var li = document.createElement('li');
				li.className = 'bck-device-item';
				var revoked = !!d.revokedAt;
				var meta = [
					ROLE_LABELS[d.role] || d.role,
					scopeLabel(d.offeringIds),
					d.createdBy ? t('by %s').replace('%s', d.createdBy) : null,
					d.lastSeenAt ? t('last seen %s').replace('%s', BCK.fmtDateTime(d.lastSeenAt)) : t('never used'),
					revoked ? t('revoked %s').replace('%s', BCK.fmtDateTime(d.revokedAt)) : null
				].filter(Boolean).join(' · ');
				var name = document.createElement('span');
				name.className = 'bck-device-item__name';
				name.textContent = d.label;
				var sub = document.createElement('span');
				sub.className = 'bck-device-item__meta';
				sub.textContent = meta;
				var box = document.createElement('span');
				box.className = 'bck-device-item__main';
				box.appendChild(name);
				box.appendChild(sub);
				li.appendChild(box);
				if (!revoked) {
					var btn = document.createElement('button');
					btn.type = 'button';
					btn.className = 'bck-btn bck-btn--danger bck-device-revoke';
					btn.dataset.id = d.id;
					btn.textContent = t('Revoke');
					li.appendChild(btn);
				}
				devList.appendChild(li);
			});
		}

		function loadDevices() {
			BCK.api('/settings/devices/list').then(function (res) {
				renderDevices(res.devices || []);
			}).catch(function (e) {
				devList.innerHTML = '<li class="bck-device-item">' +
					BCK.esc((e && e.message) || t('Could not load devices.')) + '</li>';
			});
		}
		loadDevices();

		document.getElementById('bck-device-issue').addEventListener('click', function () {
			var btn = this;
			btn.disabled = true;
			devStatus.textContent = '';
			BCK.api('/settings/devices', { method: 'POST', json: {
				label: val('bck-device-label'),
				role: val('bck-device-role'),
				offering_ids: val('bck-device-scope')
			}}).then(function (res) {
				devToken.textContent = res.device.token;
				devNew.hidden = false;
				document.getElementById('bck-device-label').value = '';
				loadDevices();
			}).catch(function (e) {
				devStatus.textContent = (e && e.message) || t('Issue failed.');
			}).finally(function () {
				btn.disabled = false;
			});
		});

		document.getElementById('bck-device-copy').addEventListener('click', function () {
			var btn = this;
			if (navigator.clipboard && devToken.textContent) {
				navigator.clipboard.writeText(devToken.textContent).then(function () {
					btn.textContent = t('Copied');
				});
			}
		});

		devList.addEventListener('click', function (ev) {
			var b = ev.target.closest('.bck-device-revoke');
			if (!b) { return; }
			BCK.confirmDialog(
				t('Revoke this device?'),
				t('The paired app on it loses access immediately. Pair it again with a new token if needed.'),
				{ confirmLabel: t('Revoke') }
			).then(function (ok) {
				if (!ok) { return; }
				b.disabled = true;
				BCK.api('/settings/devices/' + encodeURIComponent(b.dataset.id), { method: 'DELETE' })
					.then(loadDevices)
					.catch(function (e) {
						devStatus.textContent = (e && e.message) || t('Revoke failed.');
						b.disabled = false;
					});
			});
		});
	}
}());

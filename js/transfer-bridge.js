/**
 * Bridge for the shared transfer page (apps/_shared/config-transfer):
 * exposes the window.BookingCheckApi / BookingCheckMessaging contract the
 * synced js/transfer.js expects, in BookingCheck's own API envelope
 * ({success:true,data:{…}} / {success:false,error,message,fields,context}).
 * App-specific — not synced. Loads BEFORE js/transfer.js.
 */
(function () {
	'use strict';
	function requestToken() {
		return (window.OC && OC.requestToken) || '';
	}
	async function parse(resp) {
		const j = await resp.json().catch(() => null);
		if (!resp.ok || (j && (j.ok === false || j.success === false))) {
			throw {
				code: (j && j.error && (j.error.code || j.error)) || 'SERVER_ERROR',
				context: (j && j.context) || {},
			};
		}
		return j && Object.prototype.hasOwnProperty.call(j, 'data') ? j.data : j;
	}
	window.BookingCheckApi = {
		url(path) {
			return OC.generateUrl('/apps/bookingcheck' + path);
		},
		async get(path, params) {
			let u = this.url(path);
			if (params) {
				const q = Object.keys(params)
					.filter((k) => params[k] !== undefined && params[k] !== null)
					.map((k) => encodeURIComponent(k) + '=' + encodeURIComponent(params[k]))
					.join('&');
				if (q) u += '?' + q;
			}
			return parse(await fetch(u, { credentials: 'same-origin' }));
		},
		async post(path, body) {
			return parse(await fetch(this.url(path), {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', requesttoken: requestToken() },
				body: JSON.stringify(body || {}),
			}));
		},
		async upload(path, file, extra) {
			const fd = new FormData();
			fd.append('file', file);
			if (extra) {
				Object.keys(extra).forEach((k) => fd.append(k, extra[k]));
			}
			return parse(await fetch(this.url(path), {
				method: 'POST', credentials: 'same-origin',
				headers: { requesttoken: requestToken() },
				body: fd,
			}));
		},
	};
	window.BookingCheckMessaging = {
		alert(text) {
			// BCKToasts is the app-owned kind+text dedup container
			// (js/common/toasts.js — loaded on every app page).
			if (window.BCKToasts && BCKToasts.error) {
				BCKToasts.error(text);
			} else if (window.OC && OC.Notification && OC.Notification.showTemporary) {
				OC.Notification.showTemporary(text);
			}
			const el = document.getElementById('bck-transfer-result-live');
			if (el) el.textContent = text;
		},
		polite(text) {
			if (window.BCKToasts && BCKToasts.success) {
				BCKToasts.success(text);
				const el = document.getElementById('bck-transfer-result-live');
				if (el) el.textContent = text;
				return;
			}
			this.alert(text);
		},
	};
})();

(function () {
	'use strict';

	var DURATIONS = { success: 4000, warn: 5000, error: 7000 };

	function live(id, text) {
		var el = document.getElementById(id);
		if (el) {
			el.textContent = text;
		}
	}

	function ensureContainer() {
		var c = document.getElementById('bck-toasts');
		if (!c) {
			c = document.createElement('div');
			c.id = 'bck-toasts';
			c.className = 'bck-toasts';
			document.body.appendChild(c);
		}
		return c;
	}

	/**
	 * Dedup on kind+text with timer reset: an identical toast already showing
	 * gets its dismiss timer reset instead of stacking a duplicate (rapid
	 * retries, repeated API failures).
	 */
	function show(kind, regionId, message) {
		live(regionId, message);
		var container = ensureContainer();
		var dedupKey = kind + '|' + String(message);
		var existing = typeof container.querySelectorAll === 'function'
			? container.querySelectorAll('.bck-toast[data-bck-toast-key]')
			: Array.prototype.slice.call(container.childNodes || []);
		for (var i = 0; i < existing.length; i++) {
			var prev = existing[i];
			var prevKey = typeof prev.getAttribute === 'function'
				? prev.getAttribute('data-bck-toast-key')
				: prev._bckToastKey;
			if (prevKey === dedupKey || prev._bckToastKey === dedupKey) {
				if (prev._bckDismissTimer) {
					window.clearTimeout(prev._bckDismissTimer);
				}
				prev._bckDismissTimer = window.setTimeout(function () {
					if (prev.parentNode) {
						prev.parentNode.removeChild(prev);
					}
				}, DURATIONS[kind]);
				return;
			}
		}
		var toast = document.createElement('div');
		toast.className = 'bck-toast bck-toast--' + kind;
		toast.setAttribute('role', kind === 'error' ? 'alert' : 'status');
		toast.setAttribute('data-bck-toast-key', dedupKey);
		toast._bckToastKey = dedupKey;
		var text = document.createElement('span');
		text.className = 'bck-toast__text';
		text.textContent = String(message);
		var close = document.createElement('button');
		close.type = 'button';
		close.className = 'bck-toast__close';
		close.setAttribute('aria-label', t('bookingcheck', 'Dismiss'));
		close.textContent = '✕';
		close.addEventListener('click', function () {
			if (toast._bckDismissTimer) {
				window.clearTimeout(toast._bckDismissTimer);
			}
			toast.remove();
		});
		toast.appendChild(text);
		if (kind === 'error') {
			// Family contract (_shared/app-feedback): error toasts offer a
			// "Report this problem" mailto next to the message.
			try {
				var feedback = window.SbdAppFeedback;
				if (feedback && typeof feedback.buildMailto === 'function') {
					var link = document.createElement('a');
					link.className = 'bck-toast__feedback';
					link.href = feedback.buildMailto('problem', {});
					link.textContent = t('bookingcheck', 'Report this problem');
					toast.appendChild(link);
				}
			} catch (e) { /* never break the toast */ }
		}
		toast.appendChild(close);
		container.appendChild(toast);
		toast._bckDismissTimer = window.setTimeout(function () {
			if (toast.parentNode) {
				toast.parentNode.removeChild(toast);
			}
		}, DURATIONS[kind]);
	}

	window.BCKToasts = {
		success: function (message) {
			show('success', 'bck-live-region', message);
		},
		warn: function (message) {
			show('warn', 'bck-live-region', message);
		},
		error: function (message) {
			show('error', 'bck-alert-region', message);
		},
		// Back-compat aliases (contractcheck-era call sites).
		showSuccess: function (message) {
			show('success', 'bck-live-region', message);
		},
		showError: function (message) {
			show('error', 'bck-alert-region', message);
		}
	};
})();

/* PSP return — poll order status; webhook is the only truth. */
(function () {
	'use strict';
	var box = document.getElementById('bck-return-status');
	if (!box || !window.BCK) { return; }
	var ref = box.getAttribute('data-bck-ref');
	var tries = 0;

	function paint(kind, text) {
		box.className = 'bck-callout bck-callout--' + kind;
		box.innerHTML = '';
		var p = document.createElement('p');
		p.className = 'bck-callout__text';
		p.textContent = text;
		box.appendChild(p);
	}

	function poll() {
		tries += 1;
		fetch(BCK.appBase() + '/pub/api/orders/' + encodeURIComponent(ref), {
			credentials: 'same-origin',
			headers: { 'Accept': 'application/json' }
		}).then(function (r) { return r.json().catch(function () { return {}; }); })
			.then(function (b) {
				var s = b && b.data && b.data.status;
				if (s === 'paid') {
					paint('success', 'Payment confirmed — your tickets are on the way by email.');
					return;
				}
				if (s === 'failed' || s === 'expired' || s === 'cancelled') {
					paint('danger', 'Payment did not complete. The seats were released — you can book again.');
					return;
				}
				if (tries < 40) { setTimeout(poll, 3000); }
				else { paint('info', 'Still waiting for your bank. We email you the moment it lands.'); }
			})
			.catch(function () {
				if (tries < 40) { setTimeout(poll, 4000); }
			});
	}
	setTimeout(poll, 1500);
}());

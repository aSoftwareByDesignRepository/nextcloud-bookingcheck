<?php
declare(strict_types=1);
\OCA\BookingCheck\Support\AssetRegistrar::registerPublic();
/** Waitlist claim — a seat is held; one click converts it to a booking. */
$l = \OCP\Util::getL10N('bookingcheck');
$email = (string)($_['email'] ?? '');
$token = (string)($_['token'] ?? '');
$action = \OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('bookingcheck.claim.accept', ['token' => $token]);
?>
<div class="bck-pub bck-pub--narrow">
	<div class="bck-card">
		<div class="bck-card__body bck-pub__center">
			<h1 class="bck-pub__title"><?php p($l->t('A seat opened up!')); ?></h1>
			<p class="bck-pub__lead">
				<?php p($l->t('We are holding it for') . ' ' . $email . '.'); ?>
			</p>
			<div class="bck-callout bck-callout--warning">
				<p class="bck-callout__text"><?php p($l->t('Claim it now — the hold expires soon.')); ?></p>
			</div>
			<form method="post" action="<?php p($action); ?>" data-bck-claim>
				<label class="bck-field">
					<span class="bck-field__label"><?php p($l->t('Your name')); ?></span>
					<input class="bck-input" type="text" name="buyer_name" required minlength="2" maxlength="190" autocomplete="name">
					<span class="bck-field-error" data-field-error="buyer_name" hidden></span>
				</label>
				<?php $tosText = trim((string)($_['tosText'] ?? '')); ?>
				<?php if ($tosText !== ''): ?>
				<label class="bck-field bck-check">
					<input type="checkbox" name="consent" value="1" required
						aria-describedby="bck-claim-consent-error">
					<span class="bck-check__text"><?php p($l->t('I accept the terms')); ?></span>
					<span class="bck-field__hint"><?php p($tosText); ?></span>
					<span class="bck-field-error" id="bck-claim-consent-error" data-field-error="consent" hidden></span>
				</label>
				<?php endif; ?>
				<div class="bck-form-actions">
					<button type="submit" class="bck-btn bck-btn--primary bck-btn--xl">
						<?php p($l->t('Claim my seat')); ?></button>
				</div>
			</form>
		</div>
	</div>
</div>
<script>
document.querySelector('[data-bck-claim]').addEventListener('submit', async function (e) {
	e.preventDefault();
	const f = e.target;
	const btn = f.querySelector('button[type=submit]');
	btn.disabled = true;
	f.querySelectorAll('.bck-field-error').forEach(el => { el.hidden = true; el.textContent = ''; });
	try {
		const res = await fetch(f.action, {
			method: 'POST',
			headers: {'Content-Type': 'application/json'},
			body: JSON.stringify({buyer_name: f.buyer_name.value,
				consent: f.consent && f.consent.checked ? '1' : ''}),
		});
		const data = await res.json();
		if (data.success && data.checkout_url) { window.location.href = data.checkout_url; return; }
		if (data.success && data.manage_url) { window.location.href = data.manage_url; return; }
		if (data.success) { window.location.reload(); return; }
		btn.disabled = false;
		const fields = data.fields || {};
		let shown = false;
		Object.keys(fields).forEach(k => {
			const el = f.querySelector('[data-field-error="' + k + '"]');
			const input = f.querySelector('[name="' + k + '"]');
			if (input) { input.setAttribute('aria-invalid', 'true'); }
			if (el) { el.textContent = fields[k]; el.hidden = false; shown = true; }
		});
		if (!shown && window.BCK && BCK.toast) { BCK.toast.error(data.message || 'Could not claim this seat.'); }
	} catch (err) {
		btn.disabled = false;
		if (window.BCK && BCK.toast) { BCK.toast.error('Could not claim this seat.'); }
	}
});
</script>

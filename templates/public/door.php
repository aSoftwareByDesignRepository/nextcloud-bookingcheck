<?php
/**
 * Door check-in for token sessions — tablets at the venue, no NC login.
 * Two modes: 'entry' (paste the device token once) and 'door' (the
 * scanner, scoped to the token's offerings).
 *
 * @var \OCP\IL10N $l
 */
// scan + door handlers live in pages/admin.js (element-guarded — the
// admin-only handlers simply find nothing on this page)
\OCA\BookingCheck\Support\AssetRegistrar::registerPublic('admin');
$mode = (string)($_['scanMode'] ?? 'entry');
$doorLabel = trim((string)($_['doorLabel'] ?? ''));
?>
<div class="bck-pub bck-pub--narrow">
	<h1 class="bck-pub__title"><?php p($l->t('Check-in')); ?></h1>
	<?php if ($mode === 'entry'): ?>
	<p class="bck-pub__lead"><?php p($l->t('Enter your door device token to start checking people in.')); ?></p>
	<div class="bck-card">
		<div class="bck-card__body">
			<form id="bck-door-form" class="bck-form" novalidate>
				<div class="bck-field">
					<label class="bck-label" for="bck-door-token"><?php p($l->t('Device token')); ?></label>
					<input id="bck-door-token" name="token" type="password" autocomplete="off"
						spellcheck="false" required>
					<p class="bck-field__hint"><?php p($l->t('Your organizer gave you this token. You only enter it once on this device.')); ?></p>
					<p class="bck-field-error" id="bck-door-error" role="alert" hidden></p>
				</div>
				<div class="bck-form-actions">
					<button type="submit" class="bck-btn bck-btn--primary"><?php p($l->t('Start check-in')); ?></button>
				</div>
			</form>
		</div>
	</div>
	<?php else: ?>
	<p class="bck-pub__lead"><?php p($doorLabel !== ''
		? $l->t('Paste a ticket code or scan a QR — one tap confirms entry. Device: %s', [$doorLabel])
		: $l->t('Paste a ticket code or scan a QR — one tap confirms entry.')); ?></p>
	<div class="bck-card">
		<div class="bck-card__body">
			<form id="bck-scan-form" class="bck-form" data-bck-scan novalidate>
				<div class="bck-field">
					<label for="bck-scan-code" class="bck-label"><?php p($l->t('Ticket code')); ?></label>
					<input id="bck-scan-code" name="code" type="text" autocomplete="off"
						autocapitalize="characters" spellcheck="false" required
						placeholder="BCK-2026-XXXXXXXX" inputmode="text">
					<p class="bck-field__hint"><?php p($l->t('Scan the QR payload (BCK1:…) or type the order reference printed on the ticket (BCK-YYYY-XXXXXXXX).')); ?></p>
					<p class="bck-field-error" id="bck-scan-error" role="alert" hidden></p>
				</div>
				<div class="bck-form-actions">
					<button type="submit" class="bck-btn bck-btn--primary"><?php p($l->t('Look up')); ?></button>
					<button id="bck-door-out" type="button" class="bck-btn bck-btn--secondary"><?php p($l->t('Sign out')); ?></button>
				</div>
			</form>
			<div id="bck-scan-result" class="bck-callout" role="status" aria-live="polite" hidden></div>
		</div>
	</div>
	<?php endif; ?>
</div>

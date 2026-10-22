<?php
/** @var array $_ @var \OCP\IL10N $l — Settings → Booking flow */
?>
<form class="bck-card bck-settings-form" data-section="flow" novalidate>
	<div class="bck-card__body">
	<div class="bck-field">
		<label for="bck-order-hold"><?php p($l->t('Order hold (minutes)')); ?></label>
		<input id="bck-order-hold" type="number" min="1" max="525600" value="<?php p((string)$_['orderHoldMin']); ?>">
	</div>
	<div class="bck-field">
		<label for="bck-approval-hold"><?php p($l->t('Approval hold (minutes)')); ?></label>
		<input id="bck-approval-hold" type="number" min="1" max="525600" value="<?php p((string)$_['approvalHoldMin']); ?>">
	</div>
	<div class="bck-field">
		<label for="bck-payment-ttl"><?php p($l->t('Payment link valid (minutes)')); ?></label>
		<input id="bck-payment-ttl" type="number" min="1" max="525600" value="<?php p((string)$_['paymentTtlMin']); ?>">
	</div>
	<div class="bck-field">
		<label for="bck-otp-ttl"><?php p($l->t('Sign-in code valid (minutes)')); ?></label>
		<input id="bck-otp-ttl" type="number" min="1" max="525600" value="<?php p((string)$_['otpTtlMin']); ?>">
	</div>
	<div class="bck-field">
		<label for="bck-portal-sess"><?php p($l->t('Portal session (days)')); ?></label>
		<input id="bck-portal-sess" type="number" min="1" max="525600" value="<?php p((string)$_['portalSessDays']); ?>">
	</div>
	<div class="bck-field">
		<label for="bck-waitlist-offer"><?php p($l->t('Waitlist offer valid (hours)')); ?></label>
		<input id="bck-waitlist-offer" type="number" min="1" max="525600" value="<?php p((string)$_['waitlistOfferH']); ?>">
	</div>
	<p>
		<button type="submit" class="bck-btn bck-btn--primary"><?php p($l->t('Save settings')); ?></button>
		<span class="bck-settings-status" role="status" aria-live="polite"></span>
	</p>
	</div>
</form>

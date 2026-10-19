<?php
/** Checkout — the shortest possible path: who, how many, pay. */
\OCP\Util::addScript('bookingcheck', 'pages/public-checkout');
$o = $_['offering'] ?? [];
$url = \OCP\Server::get(\OCP\IURLGenerator::class);
?>
<div class="bck-pub bck-pub--narrow">
	<h1 class="bck-pub__title"><?php p((string)$o['title']); ?></h1>
	<p class="bck-pub__lead"><?php p($_['approvalRequired']
		? $l->t('Send your request — we confirm by email.')
		: $l->t('Almost done — tell us who is booking.')); ?></p>

	<form id="bck-checkout" class="bck-card bck-form" novalidate
		data-bck-slug="<?php p((string)$o['slug']); ?>"
		data-bck-approval="<?php p($_['approvalRequired'] ? '1' : '0'); ?>">

		<div class="bck-card__body">
			<div id="bck-checkout-lines" class="bck-checkout-lines"><!-- seat lines injected --></div>

			<div class="bck-field">
				<label for="bck-qty" class="bck-label"><?php p($l->t('How many seats?')); ?></label>
				<input id="bck-qty" name="qty" type="number" min="1" max="20" value="1" required inputmode="numeric">
			</div>

			<div class="bck-field">
				<label for="bck-name" class="bck-label"><?php p($l->t('Your name')); ?></label>
				<input id="bck-name" name="name" type="text" autocomplete="name" required maxlength="190">
				<p class="bck-field-error" id="bck-name-error" hidden></p>
			</div>

			<div class="bck-field">
				<label for="bck-email" class="bck-label"><?php p($l->t('Email')); ?></label>
				<input id="bck-email" name="email" type="email" autocomplete="email" required maxlength="190">
				<p class="bck-field__hint"><?php p($l->t('Tickets and your manage link go here.')); ?></p>
				<p class="bck-field-error" id="bck-email-error" hidden></p>
			</div>

			<div class="bck-field">
				<label for="bck-promo" class="bck-label"><?php p($l->t('Voucher or promo code')); ?>
					<span class="bck-label__opt">(<?php p($l->t('optional')); ?>)</span></label>
				<div class="bck-inline-form">
					<input id="bck-promo" name="code" type="text" autocomplete="off" maxlength="64">
					<button type="button" class="bck-btn bck-btn--secondary" id="bck-promo-apply"><?php p($l->t('Apply')); ?></button>
				</div>
				<p class="bck-field__hint" id="bck-promo-hint"></p>
			</div>
		</div>

		<div class="bck-card__footer">
			<div class="bck-checkout-total" id="bck-total" aria-live="polite"></div>
			<button type="submit" class="bck-btn bck-btn--primary bck-btn--xl" id="bck-pay-btn">
				<?php p($_['approvalRequired'] ? $l->t('Send request') : $l->t('Continue to payment')); ?></button>
			<p class="bck-field__hint"><?php p($l->t('You can review everything before paying.')); ?></p>
		</div>
	</form>
</div>

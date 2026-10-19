<?php
/** PSP return — we NEVER trust the redirect; show pending + poll webhook state. */
\OCA\BookingCheck\Support\AssetRegistrar::registerPublic('public-return');
$order = $_['order'] ?? [];
?>
<div class="bck-pub bck-pub--narrow">
	<div class="bck-card">
		<div class="bck-card__body bck-pub__center">
			<h1 class="bck-pub__title"><?php p($l->t('Checking your payment…')); ?></h1>
			<p class="bck-pub__lead">
				<?php p($l->t('Order') . ' ' . (string)$order['ref']); ?>
			</p>
			<div id="bck-return-status" class="bck-callout bck-callout--info" role="status"
				data-bck-ref="<?php p((string)$order['ref']); ?>">
				<p class="bck-callout__text"><?php p($l->t('Your payment is being confirmed — this takes a few seconds.')); ?></p>
			</div>
			<p class="bck-field__hint">
				<?php p($l->t('You can close this page — we email your tickets when the payment lands.')); ?>
			</p>
		</div>
	</div>
</div>

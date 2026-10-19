<?php
/** Waitlist claim — a seat is held; one click converts it to a booking. */
$email = (string)($_['email'] ?? '');
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
			<div class="bck-form-actions">
				<a class="bck-btn bck-btn--primary bck-btn--xl"
					href="<?php p(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('bookingcheck.pubCatalog.index')); ?>">
					<?php p($l->t('Claim my seat')); ?></a>
			</div>
		</div>
	</div>
</div>

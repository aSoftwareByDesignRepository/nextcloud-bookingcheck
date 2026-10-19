<?php
\OCA\BookingCheck\Support\AssetRegistrar::registerPublic();
/** Uniform 404 — same page for "gone", "not yours", and "never existed". */
$recover = !empty($_['recover']);
$url = \OCP\Server::get(\OCP\IURLGenerator::class);
?>
<div class="bck-pub bck-pub--narrow">
	<div class="bck-card">
		<div class="bck-card__body bck-pub__center">
			<h1 class="bck-pub__title"><?php p($l->t('Nothing here')); ?></h1>
			<p class="bck-pub__lead">
				<?php p($l->t('This link does not work — it may be old or mistyped.')); ?>
			</p>
			<?php if ($recover): ?>
				<p><?php p($l->t('Lost your manage link? We can email it again.')); ?></p>
				<form method="post" action="<?php p($url->linkToRoute('bookingcheck.manage.recover')); ?>" class="bck-form">
					<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken'] ?? ''); ?>">
					<div class="bck-inline-form">
						<input type="email" name="email" required maxlength="190"
							placeholder="<?php p($l->t('Your email')); ?>" aria-label="<?php p($l->t('Your email')); ?>">
						<button type="submit" class="bck-btn bck-btn--primary"><?php p($l->t('Email my link')); ?></button>
					</div>
				</form>
			<?php else: ?>
				<a class="bck-btn bck-btn--secondary" href="<?php p($url->linkToRoute('bookingcheck.pubCatalog.index')); ?>">
					<?php p($l->t('Back to the catalog')); ?></a>
			<?php endif; ?>
		</div>
	</div>
</div>

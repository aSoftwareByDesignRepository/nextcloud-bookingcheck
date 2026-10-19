<?php
/** Minimal iframe embed — one card, book button deep-links to checkout. */
$o = $_['offering'] ?? [];
$url = \OCP\Server::get(\OCP\IURLGenerator::class);
?>
<div class="bck-embed">
	<div class="bck-card bck-card--embed">
		<div class="bck-card__body">
			<h2 class="bck-card__title"><?php p((string)$o['title']); ?></h2>
			<p class="bck-card__lead">
				<?php p((int)$o['price_cents'] === 0
					? $l->t('Free')
					: (string)$o['currency'] . ' ' . number_format(((int)$o['price_cents']) / 100, 2, ',', '.')); ?>
			</p>
			<a class="bck-btn bck-btn--primary" target="_blank" rel="noopener"
				href="<?php p($url->linkToRoute('bookingcheck.pubCheckout.start', ['slug' => $o['slug']])); ?>">
				<?php p($l->t('Book now')); ?></a>
		</div>
	</div>
</div>

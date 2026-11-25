<?php
/** Public catalog — the shop window. One card per thing you can book. */
\OCA\BookingCheck\Support\AssetRegistrar::registerPublic('public');
$offerings = $_['offerings'] ?? [];
$url = \OCP\Server::get(\OCP\IURLGenerator::class);
$money = static fn (int $c, string $cur): string =>
	$c === 0 ? $l->t('Free') : $cur . ' ' . number_format($c / 100, 2, ',', '.');
?>
<div class="bck-pub">
	<header class="bck-pub__head">
		<h1 class="bck-pub__title"><?php p($l->t('Book your seat')); ?></h1>
		<p class="bck-pub__lead"><?php p($l->t('Pick what you want to join. Booking takes a minute.')); ?></p>
	</header>

	<?php if ($offerings === []): ?>
		<div class="bck-empty-state bck-card">
			<div class="bck-card__body">
				<p class="bck-empty-state__title"><?php p($l->t('Nothing bookable right now')); ?></p>
				<p class="bck-empty-state__text"><?php p($l->t('Check back soon.')); ?></p>
			</div>
		</div>
	<?php else: ?>
		<div class="bck-card-grid" role="list">
			<?php foreach ($offerings as $o): ?>
				<a class="bck-card bck-card--link" role="listitem"
					href="<?php p($url->linkToRoute('bookingcheck.pubCatalog.offering', ['slug' => $o['slug']])); ?>">
					<div class="bck-card__header">
						<div class="bck-card__header-text">
							<h2 class="bck-card__title"><?php p((string)$o['title']); ?></h2>
						</div>
						<span class="bck-badge bck-badge--primary">
							<?php p($money((int)$o['price_cents'], (string)$o['currency'])); ?></span>
					</div>
					<div class="bck-card__body">
						<?php if (!empty($o['next_start'])): ?>
							<p class="bck-muted bck-fs-sm"><?php p($l->t('Next:') . ' ' . (string)$o['next_start']); ?></p>
						<?php endif; ?>
						<?php if (!empty($o['descr'])): ?>
							<p><?php p(mb_strimwidth((string)$o['descr'], 0, 140, '…')); ?></p>
						<?php endif; ?>
					</div>
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<footer class="bck-pub__foot">
		<a href="<?php p($url->linkToRoute('bookingcheck.portal.request')); ?>">
			<?php p($l->t('Already booked? Sign in to your customer area')); ?></a>
	</footer>
</div>

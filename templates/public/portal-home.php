<?php
/** Portal home — your orders + your materials. Nothing else. */
$orders = $_['orders'] ?? [];
$materials = $_['materials'] ?? [];
$url = \OCP\Server::get(\OCP\IURLGenerator::class);
?>
<div class="bck-pub">
	<header class="bck-pub__head">
		<h1 class="bck-pub__title"><?php p($l->t('Your bookings')); ?></h1>
		<p class="bck-pub__lead"><?php p((string)$_['email']); ?></p>
	</header>

	<div class="bck-page-stack">
		<section class="bck-card" aria-labelledby="bck-portal-orders">
			<div class="bck-card__header"><h2 id="bck-portal-orders" class="bck-card__title"><?php p($l->t('Orders')); ?></h2></div>
			<div class="bck-card__body">
				<?php if ($orders === []): ?>
					<p class="bck-empty-state__text"><?php p($l->t('No bookings under this email.')); ?></p>
				<?php else: ?>
					<ul class="bck-line-list">
						<?php foreach ($orders as $o): ?>
							<li class="bck-line-list__item">
								<span><?php p((string)$o['ref'] . ' — ' . (string)$o['title']); ?></span>
								<a class="bck-btn bck-btn--small bck-btn--secondary"
									href="<?php p($url->linkToRoute('bookingcheck.portal.order', ['id' => (int)$o['id']])); ?>">
									<?php p($l->t('Open')); ?></a>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</section>

		<?php if ($materials !== []): ?>
		<section class="bck-card" aria-labelledby="bck-portal-materials">
			<div class="bck-card__header"><h2 id="bck-portal-materials" class="bck-card__title"><?php p($l->t('Your materials')); ?></h2></div>
			<div class="bck-card__body">
				<ul class="bck-line-list">
					<?php foreach ($materials as $m): ?>
						<li class="bck-line-list__item">
							<span><?php p((string)$m['title']); ?></span>
							<a class="bck-btn bck-btn--small bck-btn--primary"
								href="<?php p($url->linkToRoute('bookingcheck.portal.download', ['id' => (int)$m['id']])); ?>">
								<?php p($l->t('Download')); ?></a>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</section>
		<?php endif; ?>

		<form method="post" action="<?php p($url->linkToRoute('bookingcheck.portal.logout')); ?>">
			<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken'] ?? ''); ?>">
			<button type="submit" class="bck-btn bck-btn--ghost"><?php p($l->t('Sign out')); ?></button>
		</form>
	</div>
</div>

<?php
/**
 * Portal order detail — the "Open" link from the portal home.
 * Buyer sees every live ticket; attendee-scoped sessions see only their
 * own (the controller already filtered — the template just renders).
 *
 * @var \OCP\IL10N $l
 */
\OCA\BookingCheck\Support\AssetRegistrar::registerPublic();
$ref = (string)($_['ref'] ?? '');
$title = (string)($_['title'] ?? '');
$tickets = $_['tickets'] ?? [];
$materials = $_['materials'] ?? [];
$scope = (string)($_['scope'] ?? 'buyer');
$url = \OCP\Server::get(\OCP\IURLGenerator::class);
?>
<div class="bck-pub">
	<header class="bck-pub__head">
		<h1 class="bck-pub__title"><?php p($ref . ' — ' . $title); ?></h1>
		<p class="bck-pub__lead">
			<a href="<?php p($url->linkToRoute('bookingcheck.portal.home')); ?>"><?php p($l->t('← Back to your bookings')); ?></a>
		</p>
	</header>

	<div class="bck-page-stack">
		<section class="bck-card" aria-labelledby="bck-po-tickets">
			<div class="bck-card__header"><h2 id="bck-po-tickets" class="bck-card__title"><?php p($l->t('Tickets')); ?></h2></div>
			<div class="bck-card__body">
				<?php if ($tickets === []): ?>
					<p class="bck-empty-state__text"><?php p($l->t('No live tickets on this booking.')); ?></p>
				<?php else: ?>
					<ul class="bck-line-list">
						<?php foreach ($tickets as $t): ?>
							<li class="bck-line-list__item bck-po-ticket">
								<div>
									<strong><?php p((string)$t['attendee_name']); ?></strong>
									<?php if ($t['seat_no'] !== null): ?>
										<p class="bck-field__hint"><?php p($l->t('Seat') . ' ' . (int)$t['seat_no']); ?></p>
									<?php endif; ?>
									<?php if ($t['checked_in']): ?>
										<p class="bck-field__hint"><?php p($l->t('Already checked in')); ?></p>
									<?php endif; ?>
								</div>
								<?php if ($t['qr_svg'] !== null): ?>
									<div class="bck-ticket__qr" aria-label="<?php p($l->t('Check-in code')); ?>"><?php print_unescaped($t['qr_svg']); ?></div>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</section>

		<?php if ($materials !== []): ?>
		<section class="bck-card" aria-labelledby="bck-po-materials">
			<div class="bck-card__header"><h2 id="bck-po-materials" class="bck-card__title"><?php p($l->t('Materials')); ?></h2></div>
			<div class="bck-card__body">
				<ul class="bck-line-list">
					<?php foreach ($materials as $m): ?>
						<li class="bck-line-list__item">
							<span><?php p((string)$m['title']); ?></span>
							<span class="bck-btn-row">
								<a class="bck-btn bck-btn--small bck-btn--secondary"
									href="<?php p($url->linkToRoute('bookingcheck.portal.preview', ['id' => (int)$m['id']])); ?>"
									target="_blank" rel="noopener">
									<?php p($l->t('Preview')); ?></a>
								<a class="bck-btn bck-btn--small bck-btn--primary"
									href="<?php p($url->linkToRoute('bookingcheck.portal.download', ['id' => (int)$m['id']])); ?>">
									<?php p($l->t('Download')); ?></a>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</section>
		<?php endif; ?>

		<?php if ($scope === 'attendee'): ?>
			<p class="bck-field__hint"><?php p($l->t('You are viewing your own tickets on this booking.')); ?></p>
		<?php endif; ?>
	</div>
</div>

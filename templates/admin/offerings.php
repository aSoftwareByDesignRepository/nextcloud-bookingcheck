<?php
/** @var \OCP\IL10N $l */
$_['pageTitle'] = $l->t('Offerings');
$_['pageHelp'] = $l->t('Everything you sell — events, courses, timeslots.');
$_['pageIcon'] = 'calendar';
$_['pageActions'] = [[
	'label' => $l->t('New offering'), 'primary' => true, 'icon' => 'plus',
	'href' => $_['urls_new'] ?? \OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('bookingcheck.offering.create'),
]];
\OCA\BookingCheck\Support\Chrome::apply($_, 'offerings');
include __DIR__ . '/../common/page-start.php';

$items = $_['offerings'] ?? [];
$url = \OCP\Server::get(\OCP\IURLGenerator::class);
$typeLabel = ['event' => $l->t('Event'), 'series' => $l->t('Series'), 'slots' => $l->t('Timeslots'), 'evergreen' => $l->t('Open offer')];
?>
<div class="bck-page-stack">
	<?php if ($items === []): ?>
		<div class="bck-empty-state">
			<p class="bck-empty-state__title"><?php p($l->t('Nothing for sale yet')); ?></p>
			<p class="bck-empty-state__text"><?php p($l->t('Create your first offering — an event, a course, or bookable timeslots.')); ?></p>
			<a class="bck-btn bck-btn--primary" href="<?php p($url->linkToRoute('bookingcheck.offering.create')); ?>">
				<?php p($l->t('New offering')); ?></a>
		</div>
	<?php else: ?>
		<div class="bck-card-grid" role="list">
			<?php foreach ($items as $o): ?>
			<article class="bck-card" role="listitem">
				<div class="bck-card__header">
					<div class="bck-card__header-text">
						<h2 class="bck-card__title">
							<a href="<?php p($url->linkToRoute('bookingcheck.offering.show', ['id' => (int)$o['id']])); ?>">
								<?php p((string)$o['title']); ?></a>
						</h2>
						<p class="bck-card__lead"><?php p($typeLabel[$o['type']] ?? $o['type']); ?></p>
					</div>
					<span class="bck-badge <?php p('bck-badge--' . (['published' => 'success', 'draft' => 'neutral', 'paused' => 'warn', 'archived' => 'neutral'][$o['status']] ?? 'neutral')); ?>">
						<?php p(\OCA\BookingCheck\Support\StatusLabels::offeringStatus($l, (string)$o['status'])); ?></span>
				</div>
				<div class="bck-card__body">
					<p><?php p((string)$o['currency'] . ' ' . number_format(((int)$o['priceCents']) / 100, 2, ',', '.')); ?></p>
				</div>
				<?php if (($o['status'] ?? '') === 'draft'): ?>
				<div class="bck-card__footer">
					<div class="bck-btn-row">
						<button type="button" class="bck-btn bck-btn--primary bck-btn--small"
							data-bck-offer-transition="<?php p((int)$o['id']); ?>" data-bck-to="published">
							<?php p($l->t('Publish')); ?></button>
					</div>
				</div>
				<?php endif; ?>
			</article>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
<?php include __DIR__ . '/../common/page-end.php'; ?>

<?php
/** @var \OCP\IL10N $l */
$_['pageTitle'] = $l->t('Reviews');
$_['pageHelp'] = $l->t('Moderate what attendees wrote. Only verified bookings can review.');
$_['pageIcon'] = 'graduation-cap';
\OCA\BookingCheck\Support\Chrome::apply($_, 'reviews');
include __DIR__ . '/../common/page-start.php';
$items = $_['reviews'] ?? [];
$badge = ['pending' => 'warn', 'published' => 'success', 'hidden' => 'neutral'];
?>
<div class="bck-page-stack">
	<?php if ($items === []): ?>
		<div class="bck-empty-state"><p class="bck-empty-state__title"><?php p($l->t('No reviews yet')); ?></p></div>
	<?php else: ?>
		<div class="bck-card-grid" role="list">
		<?php foreach ($items as $r): ?>
			<article class="bck-card" role="listitem">
				<div class="bck-card__header">
					<div class="bck-card__header-text">
						<h2 class="bck-card__title"><?php p(str_repeat('★', (int)$r['rating']) . str_repeat('☆', 5 - (int)$r['rating'])); ?></h2>
					</div>
					<span class="bck-badge <?php p('bck-badge--' . ($badge[$r['status']] ?? 'neutral')); ?>"><?php p((string)$r['status']); ?></span>
				</div>
				<div class="bck-card__body">
					<p><?php p((string)$r['text']); ?></p>
					<div class="bck-btn-row">
						<?php if ($r['status'] !== 'published'): ?>
							<button type="button" class="bck-btn bck-btn--small bck-btn--primary"
								data-bck-moderate="<?php p((string)(int)$r['id']); ?>" data-bck-to="published"><?php p($l->t('Publish')); ?></button>
						<?php endif; ?>
						<?php if ($r['status'] !== 'hidden'): ?>
							<button type="button" class="bck-btn bck-btn--small bck-btn--secondary"
								data-bck-moderate="<?php p((string)(int)$r['id']); ?>" data-bck-to="hidden"><?php p($l->t('Hide')); ?></button>
						<?php endif; ?>
					</div>
				</div>
			</article>
		<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
<?php include __DIR__ . '/../common/page-end.php'; ?>

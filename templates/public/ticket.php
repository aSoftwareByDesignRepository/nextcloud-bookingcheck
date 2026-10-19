<?php
declare(strict_types=1);
/** @var array $ticket @var array $order @var ?string $qrSvg @var string $token */
$l = \OCP\Util::getL10N('bookingcheck');
$url = \OCP\Server::get(\OCP\IURLGenerator::class);
?>
<div id="content" class="app-bookingcheck bck-page" role="main">
	<div class="bck-section bck-ticket">
		<div class="bck-card">
			<div class="bck-card__head">
				<h2 class="bck-card__title"><?php p($l->t('Your ticket')); ?></h2>
				<?php if ($ticket['checked_in']): ?>
					<span class="bck-badge bck-badge--success"><?php p($l->t('Checked in')); ?></span>
				<?php endif; ?>
			</div>
			<div class="bck-card__body bck-ticket__body">
				<?php if ($qrSvg !== null): ?>
					<div class="bck-ticket__qr" aria-label="<?php p($l->t('Check-in code')); ?>"><?php print_unescaped($qrSvg); ?></div>
				<?php else: ?>
					<p class="bck-field-error"><?php p($l->t('This ticket was issued before QR rendering existed — please contact the seller.')); ?></p>
				<?php endif; ?>
				<p><strong><?php p((string)$ticket['name']); ?></strong></p>
				<?php if ($ticket['seat_no'] !== null): ?>
					<p><?php p($l->t('Seat') . ' ' . (int)$ticket['seat_no']); ?></p>
				<?php endif; ?>
				<?php if ($ticket['resource'] !== null): ?>
					<p><?php p((string)$ticket['resource']); ?></p>
				<?php endif; ?>
				<p class="bck-muted"><?php p((string)$order['ref']); ?></p>
				<a class="bck-btn bck-btn--secondary"
					href="<?php p($url->linkToRoute('bookingcheck.manage.show', ['token' => $token])); ?>">
					<?php p($l->t('Back to booking')); ?></a>
			</div>
		</div>
	</div>
</div>

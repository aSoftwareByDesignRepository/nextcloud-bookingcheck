<?php
declare(strict_types=1);
\OCA\BookingCheck\Support\AssetRegistrar::registerPublic();
/** @var array $ticket @var array $order @var ?string $qrSvg @var string $token */
$l = \OCP\Util::getL10N('bookingcheck');
$url = \OCP\Server::get(\OCP\IURLGenerator::class);
?>
<div class="bck-pub bck-ticket" role="main">
	<div class="bck-section">
		<div class="bck-card">
			<div class="bck-card__head">
				<h2 class="bck-card__title"><?php p($l->t('Your ticket')); ?></h2>
				<?php if (!empty($ticket['revoked'])): ?>
					<span class="bck-badge bck-badge--danger"><?php p($l->t('Cancelled')); ?></span>
				<?php elseif ($ticket['checked_in']): ?>
					<span class="bck-badge bck-badge--success"><?php p($l->t('Checked in')); ?></span>
				<?php endif; ?>
			</div>
			<div class="bck-card__body bck-ticket__body">
				<?php if (!empty($ticket['revoked'])): ?>
					<p class="bck-callout" role="alert"><?php p($l->t('This ticket was cancelled or refunded and is no longer valid for entry.')); ?></p>
				<?php elseif ($qrSvg !== null): ?>
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
				<p class="bck-muted" style="font-size:.85em"><?php p($l->t('If the QR will not scan, door staff can look up this order reference.')); ?></p>
				<a class="bck-btn bck-btn--secondary"
					href="<?php p($url->linkToRoute('bookingcheck.manage.show', ['token' => $token])); ?>">
					<?php p($l->t('Back to booking')); ?></a>
			</div>
		</div>
	</div>
</div>

<?php
/** Manage link — order at a glance + the legal actions for its state. */
\OCP\Util::addScript('bookingcheck', 'pages/public');
$order = $_['order'] ?? [];
$lines = $_['lines'] ?? [];
$actions = $_['actions'] ?? [];
$url = \OCP\Server::get(\OCP\IURLGenerator::class);
$token = (string)($_['token'] ?? '');
?>
<div class="bck-pub bck-pub--narrow">
	<div class="bck-card">
		<div class="bck-card__header">
			<div class="bck-card__header-text">
				<h1 class="bck-card__title"><?php p($l->t('Your booking') . ' ' . (string)$order['ref']); ?></h1>
				<p class="bck-card__lead"><?php p((string)$order['buyer_name']); ?></p>
			</div>
			<span class="bck-badge <?php p('bck-badge--' . (['paid' => 'success', 'pending_payment' => 'warn', 'pending_approval' => 'info'][$order['status']] ?? 'neutral')); ?>">
				<?php p((string)$order['status']); ?></span>
		</div>
		<div class="bck-card__body">
			<p><strong><?php p((string)$order['currency'] . ' ' . number_format(((int)$order['amount_cents']) / 100, 2, ',', '.')); ?></strong></p>
			<ul class="bck-line-list">
				<?php foreach ($lines as $ln): ?>
					<li class="bck-line-list__item">
						<span><?php p((string)($ln['label'] ?? $l->t('Seat'))); ?></span>
						<?php foreach (($ticketsByLine[(int)$ln['id']] ?? []) as $t): ?>
							<a class="bck-btn bck-btn--small bck-btn--secondary"
								href="<?php p($url->linkToRoute('bookingcheck.manage.ticket', ['token' => $token, 'ticketId' => (int)$t['id']])); ?>">
								<?php p($l->t('Ticket') . ($t['seat_no'] !== null ? ' ' . $t['seat_no'] : '')); ?></a>
						<?php endforeach; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<div class="bck-btn-row">
				<a class="bck-btn bck-btn--secondary"
					href="<?php p($url->linkToRoute('bookingcheck.manage.ics', ['token' => $token])); ?>">
					<?php p($l->t('Add to calendar')); ?></a>
				<?php if (in_array('cancel', $actions, true)): ?>
					<form method="post" action="<?php p($url->linkToRoute('bookingcheck.manage.cancel', ['token' => $token])); ?>">
						<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken'] ?? ''); ?>">
						<button type="submit" class="bck-btn bck-btn--danger"><?php p($l->t('Cancel booking')); ?></button>
					</form>
				<?php endif; ?>
				<?php if (in_array('pay', $actions, true)): ?>
					<form method="post" action="<?php p($url->linkToRoute('bookingcheck.manage.pay', ['token' => $token])); ?>">
						<button type="submit" class="bck-btn bck-btn--primary"><?php p($l->t('Pay now')); ?></button>
					</form>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>

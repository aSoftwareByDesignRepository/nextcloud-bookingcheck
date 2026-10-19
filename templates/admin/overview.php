<?php
/** @var \OCP\IL10N $l */
$_['pageTitle'] = $l->t('Overview');
$_['pageHelp'] = $l->t('Sales at a glance — revenue, orders, and what needs attention.');
$_['pageIcon'] = 'home';
\OCA\BookingCheck\Support\Chrome::apply($_, 'overview');
include __DIR__ . '/../common/page-start.php';

$stats = $_['stats'] ?? [];
$recent = $_['recentOrders'] ?? [];
$money = static fn (?int $c, string $cur = 'EUR'): string =>
	$cur . ' ' . number_format(((int)$c) / 100, 2, ',', '.');
$paid = (int)($stats['byStatus']['paid'] ?? 0);
$pending = (int)($stats['byStatus']['pending_payment'] ?? 0)
	+ (int)($stats['byStatus']['pending_approval'] ?? 0);
?>
<div class="bck-page-stack">
	<div class="bck-stat-row" role="list">
		<div class="bck-stat-tile bck-stat-tile--success" role="listitem">
			<span class="bck-stat-tile__label"><?php p($l->t('Revenue')); ?></span>
			<span class="bck-stat-tile__value"><?php p($money($stats['revenueCents'] ?? 0)); ?></span>
		</div>
		<div class="bck-stat-tile bck-stat-tile--primary" role="listitem">
			<span class="bck-stat-tile__label"><?php p($l->t('Paid orders')); ?></span>
			<span class="bck-stat-tile__value"><?php p((string)$paid); ?></span>
		</div>
		<div class="bck-stat-tile <?php p($pending > 0 ? 'bck-stat-tile--warning' : ''); ?>" role="listitem">
			<span class="bck-stat-tile__label"><?php p($l->t('Needs attention')); ?></span>
			<span class="bck-stat-tile__value"><?php p((string)$pending); ?></span>
		</div>
		<div class="bck-stat-tile" role="listitem">
			<span class="bck-stat-tile__label"><?php p($l->t('Live offerings')); ?></span>
			<span class="bck-stat-tile__value"><?php p((string)($stats['published'] ?? 0)); ?></span>
		</div>
	</div>

	<section class="bck-card" aria-labelledby="bck-recent-h">
		<div class="bck-card__header">
			<h2 id="bck-recent-h" class="bck-card__title"><?php p($l->t('Latest orders')); ?></h2>
		</div>
		<div class="bck-card__body bck-card__body--table">
			<?php if ($recent === []): ?>
				<div class="bck-empty-state">
					<p class="bck-empty-state__title"><?php p($l->t('No orders yet')); ?></p>
					<p class="bck-empty-state__text"><?php p($l->t('Orders appear here as soon as someone books.')); ?></p>
				</div>
			<?php else: ?>
				<div class="bck-table-wrap">
					<table class="bck-table bck-table--cards bck-table--hover">
						<thead><tr>
							<th scope="col"><?php p($l->t('Order')); ?></th>
							<th scope="col"><?php p($l->t('Offering')); ?></th>
							<th scope="col"><?php p($l->t('Buyer')); ?></th>
							<th scope="col" class="bck-num"><?php p($l->t('Amount')); ?></th>
							<th scope="col"><?php p($l->t('Status')); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ($recent as $o): ?>
							<tr>
								<td data-label="<?php p($l->t('Order')); ?>">
									<a href="<?php p($_['urls']['home'] . 'orders/' . (int)$o['id']); ?>"><?php p($o['ref']); ?></a>
								</td>
								<td data-label="<?php p($l->t('Offering')); ?>"><?php p((string)($o['title'] ?? '—')); ?></td>
								<td data-label="<?php p($l->t('Buyer')); ?>"><?php p((string)$o['buyer_name']); ?></td>
								<td data-label="<?php p($l->t('Amount')); ?>" class="bck-num"><?php p($money($o['amount_cents'], (string)$o['currency'])); ?></td>
								<td data-label="<?php p($l->t('Status')); ?>">
									<span class="bck-badge <?php p('bck-badge--' . (['paid' => 'success', 'pending_payment' => 'warn', 'pending_approval' => 'info', 'cancelled' => 'neutral', 'refunded' => 'neutral', 'failed' => 'danger', 'expired' => 'neutral'][$o['status']] ?? 'neutral')); ?>">
										<?php p((string)$o['status']); ?></span>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
	</section>
</div>
<?php include __DIR__ . '/../common/page-end.php'; ?>

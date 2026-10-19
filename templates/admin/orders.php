<?php
/** @var \OCP\IL10N $l */
$_['pageTitle'] = $l->t('Orders');
$_['pageHelp'] = $l->t('Every booking, newest first. Test orders are marked.');
$_['pageIcon'] = 'clipboard-list';
\OCA\BookingCheck\Support\Chrome::apply($_, 'orders');
include __DIR__ . '/../common/page-start.php';

$orders = $_['orders'] ?? [];
$badge = ['paid' => 'success', 'pending_payment' => 'warn', 'pending_approval' => 'info',
	'approved' => 'info', 'cancelled' => 'neutral', 'refunded' => 'neutral',
	'failed' => 'danger', 'expired' => 'neutral', 'declined' => 'neutral', 'created' => 'neutral'];
?>
<div class="bck-page-stack">
	<?php if ($orders === []): ?>
		<div class="bck-empty-state">
			<p class="bck-empty-state__title"><?php p($l->t('No orders yet')); ?></p>
		</div>
	<?php else: ?>
		<div class="bck-table-wrap">
			<table class="bck-table bck-table--cards bck-table--hover">
				<thead><tr>
					<th scope="col"><?php p($l->t('Ref')); ?></th>
					<th scope="col"><?php p($l->t('Offering')); ?></th>
					<th scope="col"><?php p($l->t('Buyer')); ?></th>
					<th scope="col" class="bck-num"><?php p($l->t('Amount')); ?></th>
					<th scope="col"><?php p($l->t('Status')); ?></th>
					<th scope="col"><?php p($l->t('Placed')); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ($orders as $o): ?>
					<tr>
						<td data-label="<?php p($l->t('Ref')); ?>">
							<a href="<?php p($_['urls']['home'] . 'orders/' . (int)$o['id']); ?>"><?php p($o['ref']); ?></a>
							<?php if ((int)($o['is_test'] ?? 0) === 1): ?>
								<span class="bck-badge bck-badge--info"><?php p($l->t('test')); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php p($l->t('Offering')); ?>"><?php p((string)($o['title'] ?? '—')); ?></td>
						<td data-label="<?php p($l->t('Buyer')); ?>"><?php p((string)$o['buyer_name']); ?></td>
						<td data-label="<?php p($l->t('Amount')); ?>" class="bck-num">
							<?php p((string)$o['currency'] . ' ' . number_format(((int)$o['amount_cents']) / 100, 2, ',', '.')); ?></td>
						<td data-label="<?php p($l->t('Status')); ?>">
							<span class="bck-badge <?php p('bck-badge--' . ($badge[$o['status']] ?? 'neutral')); ?>">
								<?php p((string)$o['status']); ?></span>
						</td>
						<td data-label="<?php p($l->t('Placed')); ?>"><?php p((string)$o['created_at']); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</div>
<?php include __DIR__ . '/../common/page-end.php'; ?>

<?php
/** @var \OCP\IL10N $l */
use OCA\BookingCheck\Support\StatusLabels;

$_['pageTitle'] = $l->t('Orders');
$_['pageHelp'] = $l->t('Every booking, newest first. Test orders are marked.');
$_['pageIcon'] = 'clipboard-list';
\OCA\BookingCheck\Support\Chrome::apply($_, 'orders');
include __DIR__ . '/../common/page-start.php';

$orders = $_['orders'] ?? [];
$filterStatus = (string)($_['filterStatus'] ?? '');
$filterQ = (string)($_['filterQ'] ?? '');
$total = (int)($_['total'] ?? count($orders));
$truncated = (bool)($_['truncated'] ?? false);
$ordersUrl = (string)($_['urls']['home'] ?? '') . 'orders';
$href = static function (array $extra) use ($ordersUrl, $filterQ): string {
	$query = array_filter(
		$extra + ($filterQ !== '' ? ['q' => $filterQ] : []),
		static fn ($v) => $v !== null && $v !== '' && $v !== false,
	);
	return $ordersUrl . ($query === [] ? '' : '?' . http_build_query($query));
};
$pills = [
	'' => $l->t('All'),
	'paid' => StatusLabels::label($l, 'paid'),
	'pending_payment' => StatusLabels::label($l, 'pending_payment'),
	'pending_approval' => StatusLabels::label($l, 'pending_approval'),
	'cancelled' => StatusLabels::label($l, 'cancelled'),
	'refunded' => StatusLabels::label($l, 'refunded'),
	'failed' => StatusLabels::label($l, 'failed'),
];
?>
<div class="bck-page-stack">
	<nav class="bck-quick-filters" aria-label="<?php p($l->t('Order status')); ?>">
		<span class="bck-quick-filters__label"><?php p($l->t('Status')); ?></span>
		<div class="bck-filter-bar">
			<?php foreach ($pills as $key => $pillLabel): ?>
				<a class="bck-filter" href="<?php p($href($key === '' ? ['status' => null] : ['status' => $key])); ?>"
					<?php if ($filterStatus === $key): ?>aria-current="true"<?php endif; ?>><?php p($pillLabel); ?></a>
			<?php endforeach; ?>
		</div>
	</nav>
	<form method="get" action="<?php p($ordersUrl); ?>" role="search" class="bck-filter-panel__form">
		<div class="bck-filter-grid bck-filter-grid--search">
			<div class="bck-filter-field">
				<label class="bck-filter-field__label" for="bck-orders-q"><?php p($l->t('Search')); ?></label>
				<div class="bck-filter-field__control">
					<input class="bck-input" type="search" id="bck-orders-q" name="q" value="<?php p($filterQ); ?>"
						placeholder="<?php p($l->t('Reference, buyer name or email')); ?>">
				</div>
			</div>
			<div class="bck-filter-actions">
				<?php if ($filterStatus !== ''): ?>
				<input type="hidden" name="status" value="<?php p($filterStatus); ?>">
				<?php endif; ?>
				<button type="submit" class="bck-btn bck-btn--primary"><?php p($l->t('Search')); ?></button>
				<?php if ($filterQ !== '' || $filterStatus !== ''): ?>
				<a class="bck-btn bck-btn--secondary" href="<?php p($ordersUrl); ?>"><?php p($l->t('Clear')); ?></a>
				<?php endif; ?>
			</div>
		</div>
	</form>
	<div class="bck-list-head">
		<h2 class="bck-list-head__title"><?php p($l->t('Bookings')); ?></h2>
		<span class="bck-list-head__count"><?php p($l->n('%n order', '%n orders', $total)); ?></span>
	</div>
	<?php if ($orders === []): ?>
		<div class="bck-empty-state">
			<p class="bck-empty-state__title"><?php p($filterQ !== '' || $filterStatus !== '' ? $l->t('No orders match this filter') : $l->t('No orders yet')); ?></p>
			<?php if ($filterQ !== '' || $filterStatus !== ''): ?>
			<p><a class="bck-btn bck-btn--secondary" href="<?php p($ordersUrl); ?>"><?php p($l->t('Clear filters')); ?></a></p>
			<?php endif; ?>
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
						<td data-cell="<?php p($l->t('Ref')); ?>">
							<a href="<?php p($_['urls']['home'] . 'orders/' . (int)$o['id']); ?>"><?php p($o['ref']); ?></a>
							<?php if ((int)($o['is_test'] ?? 0) === 1): ?>
								<span class="bck-badge bck-badge--info"><?php p($l->t('test')); ?></span>
							<?php endif; ?>
						</td>
						<td data-cell="<?php p($l->t('Offering')); ?>"><?php p((string)($o['title'] ?? '—')); ?></td>
						<td data-cell="<?php p($l->t('Buyer')); ?>"><?php p((string)$o['buyer_name']); ?></td>
						<td data-cell="<?php p($l->t('Amount')); ?>" class="bck-num">
							<?php p((string)$o['currency'] . ' ' . number_format(((int)$o['amount_cents']) / 100, 2, ',', '.')); ?></td>
						<td data-cell="<?php p($l->t('Status')); ?>">
							<span class="bck-badge <?php p('bck-badge--' . StatusLabels::badge((string)$o['status'])); ?>">
								<?php p(StatusLabels::label($l, (string)$o['status'])); ?></span>
						</td>
						<td data-cell="<?php p($l->t('Placed')); ?>"><?php p((string)$o['created_at']); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php if ($truncated): ?>
		<p class="bck-muted"><?php p($l->t('Showing the newest 500 — narrow the filter to find older orders.')); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</div>
<?php include __DIR__ . '/../common/page-end.php'; ?>

<?php
/** @var \OCP\IL10N $l */
$_['pageTitle'] = $l->t('Vouchers');
$_['pageHelp'] = $l->t('Credit codes customers can redeem at checkout.');
$_['pageIcon'] = 'file-check';
$_['pageActions'] = [['label' => $l->t('New voucher'), 'primary' => true, 'icon' => 'plus', 'id' => 'bck-new-voucher']];
\OCA\BookingCheck\Support\Chrome::apply($_, 'vouchers');
include __DIR__ . '/../common/page-start.php';
$items = $_['vouchers'] ?? [];
?>
<div class="bck-page-stack">
	<?php if ($items === []): ?>
		<div class="bck-empty-state">
			<p class="bck-empty-state__title"><?php p($l->t('No vouchers yet')); ?></p>
		</div>
	<?php else: ?>
		<div class="bck-table-wrap">
			<table class="bck-table bck-table--cards bck-table--hover">
				<thead><tr>
					<th scope="col"><?php p($l->t('Code')); ?></th>
					<th scope="col" class="bck-num"><?php p($l->t('Credit')); ?></th>
					<th scope="col"><?php p($l->t('Status')); ?></th>
					<th scope="col"><span class="bck-sr-only"><?php p($l->t('Actions')); ?></span></th>
				</tr></thead>
				<tbody>
				<?php foreach ($items as $v): ?>
					<tr>
						<td data-cell="<?php p($l->t('Code')); ?>"><code><?php p((string)$v['code']); ?></code></td>
						<td data-cell="<?php p($l->t('Credit')); ?>" class="bck-num">
							<?php p(number_format(((int)$v['remainingCents']) / 100, 2, ',', '.')
								. ' / ' . number_format(((int)$v['creditCents']) / 100, 2, ',', '.')
								. ' ' . (string)($v['currency'] ?? '')); ?></td>
						<td data-cell="<?php p($l->t('Status')); ?>">
							<span class="bck-badge <?php p((int)$v['active'] === 1 ? 'bck-badge--success' : 'bck-badge--neutral'); ?>">
								<?php p((int)$v['active'] === 1 ? $l->t('active') : $l->t('off')); ?></span>
						</td>
						<td class="bck-table-actions-col">
							<?php if ((int)$v['active'] === 1): ?>
								<button type="button" class="bck-btn bck-btn--small bck-btn--danger"
									data-bck-delete="/api/vouchers/<?php p((string)(int)$v['id']); ?>"><?php p($l->t('Revoke')); ?></button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</div>
<?php include __DIR__ . '/../common/page-end.php'; ?>

<?php
/** @var \OCP\IL10N $l */
$_['pageTitle'] = $l->t('Promotions');
$_['pageHelp'] = $l->t('Discount codes applied at checkout.');
$_['pageIcon'] = 'activity';
$_['pageActions'] = [['label' => $l->t('New promotion'), 'primary' => true, 'icon' => 'plus', 'id' => 'bck-new-promo']];
\OCA\BookingCheck\Support\Chrome::apply($_, 'promos');
include __DIR__ . '/../common/page-start.php';
$items = $_['promos'] ?? [];
?>
<div class="bck-page-stack">
	<?php if ($items === []): ?>
		<div class="bck-empty-state">
			<p class="bck-empty-state__title"><?php p($l->t('No promotions yet')); ?></p>
		</div>
	<?php else: ?>
		<div class="bck-table-wrap">
			<table class="bck-table bck-table--cards bck-table--hover">
				<thead><tr>
					<th scope="col"><?php p($l->t('Code')); ?></th>
					<th scope="col"><?php p($l->t('Discount')); ?></th>
					<th scope="col"><?php p($l->t('Used')); ?></th>
					<th scope="col"><?php p($l->t('Status')); ?></th>
					<th scope="col"><span class="bck-sr-only"><?php p($l->t('Actions')); ?></span></th>
				</tr></thead>
				<tbody>
				<?php foreach ($items as $p_): ?>
					<tr>
						<td data-label="<?php p($l->t('Code')); ?>"><code><?php p((string)$p_['code']); ?></code></td>
						<td data-label="<?php p($l->t('Discount')); ?>">
							<?php p($p_['kind'] === 'percent'
								? (string)$p_['percent'] . ' %'
								: number_format(((int)$p_['amount_cents']) / 100, 2, ',', '.') . ' ' . (string)($p_['currency'] ?? '')); ?></td>
						<td data-label="<?php p($l->t('Used')); ?>"><?php p((string)(int)$p_['redeemed_count'] . '/' . (int)$p_['redemption_limit']); ?></td>
						<td data-label="<?php p($l->t('Status')); ?>">
							<span class="bck-badge <?php p((int)$p_['active'] === 1 ? 'bck-badge--success' : 'bck-badge--neutral'); ?>">
								<?php p((int)$p_['active'] === 1 ? $l->t('active') : $l->t('inactive')); ?></span>
						</td>
						<td class="bck-table-actions-col">
							<button type="button" class="bck-btn bck-btn--small bck-btn--danger"
								data-bck-delete="/api/promos/<?php p((string)(int)$p_['id']); ?>"><?php p($l->t('Deactivate')); ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</div>
<?php include __DIR__ . '/../common/page-end.php'; ?>

<?php
/** @var \OCP\IL10N $l */
$_['pageTitle'] = $l->t('Webhooks');
$_['pageHelp'] = $l->t('Push booking events to your own systems. Signed with HMAC-SHA256.');
$_['pageIcon'] = 'activity';
$_['pageActions'] = [['label' => $l->t('New webhook'), 'primary' => true, 'icon' => 'plus', 'id' => 'bck-new-webhook']];
\OCA\BookingCheck\Support\Chrome::apply($_, 'webhooks');
include __DIR__ . '/../common/page-start.php';
$items = $_['webhooks'] ?? [];
?>
<div class="bck-page-stack">
	<?php if ($items === []): ?>
		<div class="bck-empty-state">
			<p class="bck-empty-state__title"><?php p($l->t('No webhooks configured')); ?></p>
			<p class="bck-empty-state__text"><?php p($l->t('Events like order.paid are pushed as signed JSON to your endpoint.')); ?></p>
		</div>
	<?php else: ?>
		<div class="bck-table-wrap">
			<table class="bck-table bck-table--cards bck-table--hover">
				<thead><tr>
					<th scope="col"><?php p($l->t('Endpoint')); ?></th>
					<th scope="col"><?php p($l->t('Events')); ?></th>
					<th scope="col"><?php p($l->t('Status')); ?></th>
					<th scope="col"><span class="bck-sr-only"><?php p($l->t('Actions')); ?></span></th>
				</tr></thead>
				<tbody>
				<?php foreach ($items as $w): ?>
					<tr>
						<td data-label="<?php p($l->t('Endpoint')); ?>"><code><?php p((string)$w['url']); ?></code></td>
						<td data-label="<?php p($l->t('Events')); ?>"><?php p(implode(', ', array_map('strval', $w['events'] ?? []))); ?></td>
						<td data-label="<?php p($l->t('Status')); ?>">
							<span class="bck-badge <?php p((int)$w['active'] === 1 ? 'bck-badge--success' : 'bck-badge--neutral'); ?>">
								<?php p((int)$w['active'] === 1 ? $l->t('active') : $l->t('off')); ?></span>
						</td>
						<td class="bck-table-actions-col">
							<button type="button" class="bck-btn bck-btn--small bck-btn--secondary"
								data-bck-webhook-rotate="<?php p((string)(int)$w['id']); ?>"><?php p($l->t('Rotate secret')); ?></button>
							<button type="button" class="bck-btn bck-btn--small bck-btn--danger"
								data-bck-delete="/api/webhooks/<?php p((string)(int)$w['id']); ?>"><?php p($l->t('Delete')); ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</div>
<?php include __DIR__ . '/../common/page-end.php'; ?>

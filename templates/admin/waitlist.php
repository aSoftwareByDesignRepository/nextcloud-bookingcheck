<?php
/** @var \OCP\IL10N $l */
$_['pageTitle'] = $l->t('Waitlist');
$_['pageHelp'] = $l->t('People waiting for a seat. Offers are sent automatically when seats free up.');
$_['pageIcon'] = 'repeat';
\OCA\BookingCheck\Support\Chrome::apply($_, 'waitlist');
include __DIR__ . '/../common/page-start.php';
$entries = $_['entries'] ?? [];
$badge = ['waiting' => 'info', 'offered' => 'warn', 'claimed' => 'success', 'expired' => 'neutral', 'declined' => 'neutral'];
?>
<div class="bck-page-stack">
	<?php if ($entries === []): ?>
		<div class="bck-empty-state">
			<p class="bck-empty-state__title"><?php p($l->t('Nobody waiting')); ?></p>
		</div>
	<?php else: ?>
		<div class="bck-table-wrap">
			<table class="bck-table bck-table--cards bck-table--hover">
				<thead><tr>
					<th scope="col"><?php p($l->t('Email')); ?></th>
					<th scope="col"><?php p($l->t('Target')); ?></th>
					<th scope="col"><?php p($l->t('State')); ?></th>
					<th scope="col"><?php p($l->t('Joined')); ?></th>
					<th scope="col"><span class="bck-sr-only"><?php p($l->t('Actions')); ?></span></th>
				</tr></thead>
				<tbody>
				<?php foreach ($entries as $w): ?>
					<tr>
						<td data-cell="<?php p($l->t('Email')); ?>"><?php p((string)$w['email']); ?></td>
						<td data-cell="<?php p($l->t('Target')); ?>"><?php p((string)$w['targetType'] . ' #' . (int)$w['targetId']); ?></td>
						<td data-cell="<?php p($l->t('State')); ?>">
							<span class="bck-badge <?php p('bck-badge--' . ($badge[$w['state']] ?? 'neutral')); ?>"><?php p((string)$w['state']); ?></span>
						</td>
						<td data-cell="<?php p($l->t('Joined')); ?>"><?php p((string)$w['createdAt']); ?></td>
						<td class="bck-table-actions-col">
							<?php if (in_array($w['state'], ['waiting', 'offered'], true)): ?>
								<button type="button" class="bck-btn bck-btn--small bck-btn--danger"
									data-bck-waitlist-remove="<?php p((string)(int)$w['id']); ?>"><?php p($l->t('Remove')); ?></button>
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

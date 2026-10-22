<?php
/** @var \OCP\IL10N $l */
$_['pageTitle'] = $l->t('Audit log');
$_['pageHelp'] = $l->t('Tamper-evident record of every state change. Append-only.');
$_['pageIcon'] = 'scroll-text';
\OCA\BookingCheck\Support\Chrome::apply($_, 'audit');
include __DIR__ . '/../common/page-start.php';
$entries = $_['entries'] ?? [];
?>
<div class="bck-page-stack">
	<?php if ($entries === []): ?>
		<div class="bck-empty-state"><p class="bck-empty-state__title"><?php p($l->t('Nothing recorded yet')); ?></p></div>
	<?php else: ?>
		<div class="bck-table-wrap">
			<table class="bck-table bck-table--cards">
				<thead><tr>
					<th scope="col"><?php p($l->t('When')); ?></th>
					<th scope="col"><?php p($l->t('Actor')); ?></th>
					<th scope="col"><?php p($l->t('Action')); ?></th>
					<th scope="col"><?php p($l->t('From → To')); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ($entries as $e): ?>
					<tr>
						<td data-cell="<?php p($l->t('When')); ?>"><?php p((string)$e['created_at']); ?></td>
						<td data-cell="<?php p($l->t('Actor')); ?>"><?php p((string)$e['actor']); ?></td>
						<td data-cell="<?php p($l->t('Action')); ?>"><code><?php p((string)$e['action']); ?></code></td>
						<td data-cell="<?php p($l->t('From → To')); ?>">
							<?php p((string)($e['from_state'] ?? '—') . ' → ' . (string)($e['to_state'] ?? '—')); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</div>
<?php include __DIR__ . '/../common/page-end.php'; ?>

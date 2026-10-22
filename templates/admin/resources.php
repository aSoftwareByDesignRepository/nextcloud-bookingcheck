<?php
/** @var \OCP\IL10N $l */
$_['pageTitle'] = $l->t('Resources');
$_['pageHelp'] = $l->t('Rooms, trainers, equipment — things that can be booked out.');
$_['pageIcon'] = 'layout-grid';
$_['pageActions'] = [['label' => $l->t('New resource'), 'primary' => true, 'icon' => 'plus', 'id' => 'bck-new-resource']];
\OCA\BookingCheck\Support\Chrome::apply($_, 'resources');
include __DIR__ . '/../common/page-start.php';
$items = $_['resources'] ?? [];
?>
<script type="application/json" id="bck-resource-offerings"><?php
	p(json_encode(array_map(fn ($id, $title) => ['id' => $id, 'title' => $title],
		array_keys($_['offerings'] ?? []), array_values($_['offerings'] ?? [])),
		JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE));
?></script>
<div class="bck-page-stack">
	<?php if ($items === []): ?>
		<div class="bck-empty-state">
			<p class="bck-empty-state__title"><?php p($l->t('No resources yet')); ?></p>
			<p class="bck-empty-state__text"><?php p($l->t('Add a room, a trainer, or equipment to prevent double-booking.')); ?></p>
		</div>
	<?php else: ?>
		<div class="bck-table-wrap">
			<table class="bck-table bck-table--cards bck-table--hover">
				<thead><tr>
					<th scope="col"><?php p($l->t('Name')); ?></th>
					<th scope="col"><?php p($l->t('Offering')); ?></th>
					<th scope="col" class="bck-num"><?php p($l->t('Capacity')); ?></th>
					<th scope="col"><?php p($l->t('Status')); ?></th>
					<th scope="col"><span class="bck-sr-only"><?php p($l->t('Actions')); ?></span></th>
				</tr></thead>
				<tbody>
				<?php foreach ($items as $r): ?>
					<tr>
						<td data-cell="<?php p($l->t('Name')); ?>"><?php p((string)$r['name']); ?></td>
						<td data-cell="<?php p($l->t('Offering')); ?>"><?php p((string)($_['offerings'][(int)$r['offeringId']] ?? '#' . (int)$r['offeringId'])); ?></td>
						<td data-cell="<?php p($l->t('Capacity')); ?>" class="bck-num"><?php p((string)(int)$r['capacity']); ?></td>
						<td data-cell="<?php p($l->t('Status')); ?>">
							<span class="bck-badge <?php p((int)$r['active'] === 1 ? 'bck-badge--success' : 'bck-badge--neutral'); ?>">
								<?php p((int)$r['active'] === 1 ? $l->t('active') : $l->t('off')); ?></span>
						</td>
						<td class="bck-table-actions-col">
							<button type="button" class="bck-btn bck-btn--small bck-btn--danger"
								data-bck-delete="/api/resources/<?php p((string)(int)$r['id']); ?>"><?php p($l->t('Delete')); ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</div>
<?php include __DIR__ . '/../common/page-end.php'; ?>

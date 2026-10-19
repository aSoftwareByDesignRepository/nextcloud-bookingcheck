<?php
/** @var \OCP\IL10N $l */
$_['pageTitle'] = $l->t('Offering');
$_['pageIcon'] = 'calendar';
$_['pageActions'] = [[
	'label' => $l->t('Edit'), 'primary' => false, 'icon' => 'edit', 'id' => 'bck-edit-offering',
]];
\OCA\BookingCheck\Support\Chrome::apply($_, 'offerings');
include __DIR__ . '/../common/page-start.php';
?>
<div class="bck-page-stack" data-bck-page-detail="<?php p((string)(int)($_['offeringId'] ?? 0)); ?>">
	<div class="bck-card" id="bck-offering-summary">
		<div class="bck-card__body">
			<p class="bck-empty-state__text"><?php p($l->t('Loading…')); ?></p>
		</div>
	</div>
</div>
<?php include __DIR__ . '/../common/page-end.php'; ?>

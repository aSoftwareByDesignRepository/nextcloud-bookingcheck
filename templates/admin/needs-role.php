<?php
/** @var \OCP\IL10N $l */
$_['pageTitle'] = $l->t('Access needed');
$_['pageIcon'] = 'lock';
$_['hideScopeStrip'] = true;
\OCA\BookingCheck\Support\Chrome::apply($_, 'needs-role');
include __DIR__ . '/../common/page-start.php';
?>
<div class="bck-page-stack">
	<div class="bck-empty-state">
		<p class="bck-empty-state__title"><?php p($l->t('You need the Organizer role')); ?></p>
		<p class="bck-empty-state__text">
			<?php p($l->t('BookingCheck is limited to members of the bookingcheck-admin group. Ask your Nextcloud administrator to add you.')); ?>
		</p>
	</div>
</div>
<?php include __DIR__ . '/../common/page-end.php'; ?>

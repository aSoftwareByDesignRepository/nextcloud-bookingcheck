<?php
/** @var array $_ @var \OCP\IL10N $l */
$_['pageTitle'] = $l->t('Access needed');
$_['pageIcon'] = 'lock';
$_['hideScopeStrip'] = true;
\OCA\BookingCheck\Support\Chrome::apply($_, 'needs-role');
include __DIR__ . '/../common/page-start.php';
$needsAdmin = !empty($_['needsAdmin']);
?>
<div class="bck-page-stack">
	<div class="bck-empty-state">
		<p class="bck-empty-state__title">
			<?php p($needsAdmin ? $l->t('You need the administrator role') : $l->t('You need the Organizer role')); ?>
		</p>
		<p class="bck-empty-state__text">
			<?php if ($needsAdmin): ?>
				<?php p($l->t('BookingCheck settings are limited to app administrators. Ask a BookingCheck or Nextcloud administrator to add you.')); ?>
			<?php else: ?>
				<?php p($l->t('BookingCheck is limited to allowed users and the bookingcheck_admin group. Ask a BookingCheck administrator to add you.')); ?>
			<?php endif; ?>
		</p>
	</div>
</div>
<?php include __DIR__ . '/../common/page-end.php'; ?>

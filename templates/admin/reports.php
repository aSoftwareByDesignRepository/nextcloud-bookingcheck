<?php
/** @var \OCP\IL10N $l */
$_['pageTitle'] = $l->t('Reports');
$_['pageHelp'] = $l->t('Export your booking data as a spreadsheet-safe CSV.');
$_['pageIcon'] = 'file-text';
\OCA\BookingCheck\Support\Chrome::apply($_, 'reports');
include __DIR__ . '/../common/page-start.php';
$url = \OCP\Server::get(\OCP\IURLGenerator::class);
?>
<div class="bck-page-stack bck-page-stack--form">
	<section class="bck-card" aria-labelledby="bck-export-h">
		<div class="bck-card__header"><h2 id="bck-export-h" class="bck-card__title"><?php p($l->t('Orders export')); ?></h2></div>
		<div class="bck-card__body">
			<p><?php p($l->t('All real orders with buyer, amount, and status. Test orders are excluded.')); ?></p>
			<form method="post" action="<?php p($url->linkToRoute('bookingcheck.report.export')); ?>">
				<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken'] ?? ''); ?>">
				<button type="submit" class="bck-btn bck-btn--primary">
					<?php p($l->t('Download CSV')); ?></button>
			</form>
		</div>
	</section>
</div>
<?php include __DIR__ . '/../common/page-end.php'; ?>

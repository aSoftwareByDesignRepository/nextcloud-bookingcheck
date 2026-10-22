<?php
/** @var \OCP\IL10N $l */
$_['pageTitle'] = $l->t('Reports');
$_['pageHelp'] = $l->t('Export your booking data — spreadsheet-safe CSV, DATEV, and full offering archives.');
$_['pageIcon'] = 'file-text';
\OCA\BookingCheck\Support\Chrome::apply($_, 'reports');
include __DIR__ . '/../common/page-start.php';
$url = \OCP\Server::get(\OCP\IURLGenerator::class);
$offerings = $_['offerings'] ?? [];
$abuse = $_['abuseReports'] ?? [];
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

	<section class="bck-card" aria-labelledby="bck-datev-h">
		<div class="bck-card__header"><h2 id="bck-datev-h" class="bck-card__title"><?php p($l->t('DATEV export')); ?></h2></div>
		<div class="bck-card__body">
			<p><?php p($l->t('Booking ledger (EXTF format) for your tax advisor. Defaults to last month when the dates are empty.')); ?></p>
			<form method="get" action="<?php p($url->linkToRoute('bookingcheck.report.datev')); ?>"
				class="bck-field-row" style="align-items:flex-end">
				<div class="bck-field">
					<label class="bck-label" for="bck-datev-from"><?php p($l->t('From')); ?></label>
					<input id="bck-datev-from" name="from" type="date">
				</div>
				<div class="bck-field">
					<label class="bck-label" for="bck-datev-to"><?php p($l->t('To')); ?></label>
					<input id="bck-datev-to" name="to" type="date">
				</div>
				<button type="submit" class="bck-btn bck-btn--primary"><?php p($l->t('Download DATEV')); ?></button>
			</form>
		</div>
	</section>

	<?php if ($offerings !== []): ?>
	<section class="bck-card" aria-labelledby="bck-zip-h">
		<div class="bck-card__header"><h2 id="bck-zip-h" class="bck-card__title"><?php p($l->t('Offering archive')); ?></h2></div>
		<div class="bck-card__body">
			<p><?php p($l->t('Roster, materials, and attendee uploads of one offering as a ZIP — for records or legal hold.')); ?></p>
			<ul class="bck-list-cards">
				<?php foreach ($offerings as $of): ?>
				<li class="bck-list-cards__item">
					<span><?php p((string)$of['title']); ?></span>
					<span class="bck-btn-row">
						<a class="bck-btn bck-btn--secondary" href="<?php p($url->linkToRoute('bookingcheck.report.exportZip', ['offeringId' => (int)$of['id']])); ?>"><?php p($l->t('ZIP')); ?></a>
						<a class="bck-btn bck-btn--secondary" href="<?php p($url->linkToRoute('bookingcheck.report.downloadStats', ['offeringId' => (int)$of['id']])); ?>"><?php p($l->t('Download stats')); ?></a>
					</span>
				</li>
				<?php endforeach; ?>
			</ul>
		</div>
	</section>
	<?php endif; ?>

	<?php if ($abuse !== []): ?>
	<section class="bck-card" aria-labelledby="bck-abuse-h">
		<div class="bck-card__header"><h2 id="bck-abuse-h" class="bck-card__title"><?php p($l->t('Reported content')); ?>
			<span class="bck-badge bck-badge--warn"><?php p((string)count($abuse)); ?></span></h2></div>
		<div class="bck-card__body">
			<p><?php p($l->t('Attendees flagged shared files. Decide for each report whether the file stays or is deleted.')); ?></p>
			<ul class="bck-list-cards">
				<?php foreach ($abuse as $r): ?>
				<li class="bck-list-cards__item">
					<span>
						<strong>#<?php p((int)$r['id']); ?></strong> — <?php p((string)$r['reason']); ?><br>
						<small><?php p($l->t('Upload') . ' #' . (int)$r['uploadId'] . ' · ' . (string)$r['createdAt']); ?></small>
					</span>
					<span class="bck-btn-row">
						<button type="button" class="bck-btn bck-btn--secondary"
							data-bck-abuse="<?php p((int)$r['id']); ?>" data-bck-action="keep"><?php p($l->t('Keep file')); ?></button>
						<button type="button" class="bck-btn bck-btn--warn"
							data-bck-abuse="<?php p((int)$r['id']); ?>" data-bck-action="purge"><?php p($l->t('Delete file')); ?></button>
					</span>
				</li>
				<?php endforeach; ?>
			</ul>
		</div>
	</section>
	<?php endif; ?>
</div>
<?php include __DIR__ . '/../common/page-end.php'; ?>

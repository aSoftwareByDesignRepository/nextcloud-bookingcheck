<?php
/** @var \OCP\IL10N $l */
$_['pageTitle'] = $l->t('BookingCheck Pro');
$_['pageIcon'] = 'star';
$_['hideScopeStrip'] = true;
\OCA\BookingCheck\Support\Chrome::apply($_, 'pro');
$settingsUrl = \OCP\Server::get(\OCP\IURLGenerator::class)
	->linkToRoute('settings.AdminSettings.index', ['section' => 'bookingcheck']);
include __DIR__ . '/../common/page-start.php';
?>
<div class="bck-page-stack">
	<div class="bck-empty-state">
		<p class="bck-empty-state__title"><?php p($l->t('This is a Pro feature')); ?></p>
		<p class="bck-empty-state__text">
			<?php p($l->t('Vouchers, promotions, waitlists, the customer portal, embeds, webhooks, materials, reviews, resources, intake questions, reports and the companion apps ship with BookingCheck Pro.')); ?>
		</p>
		<p class="bck-empty-state__text">
			<?php p($l->t('Enter your license key under Settings → General to unlock them.')); ?>
		</p>
		<div class="bck-form-actions">
			<a class="bck-btn bck-btn--primary" href="<?php p($settingsUrl); ?>">
				<?php p($l->t('Open license settings')); ?></a>
		</div>
	</div>
</div>
<?php include __DIR__ . '/../common/page-end.php'; ?>

<?php
/** @var \OCP\IL10N $l */
$_['pageTitle'] = $l->t('Check-in');
$_['pageHelp'] = $l->t('Paste a ticket code or scan a QR — one tap confirms entry.');
$_['pageIcon'] = 'qr-code';
\OCA\BookingCheck\Support\Chrome::apply($_, 'scan');
include __DIR__ . '/../common/page-start.php';
?>
<div class="bck-page-stack bck-page-stack--form">
	<section class="bck-card" aria-labelledby="bck-scan-h">
		<div class="bck-card__header"><h2 id="bck-scan-h" class="bck-card__title"><?php p($l->t('Ticket lookup')); ?></h2></div>
		<div class="bck-card__body">
			<form id="bck-scan-form" class="bck-form" data-bck-scan novalidate>
				<div class="bck-field">
					<label for="bck-scan-code" class="bck-label"><?php p($l->t('Ticket code')); ?></label>
					<input id="bck-scan-code" name="code" type="text" autocomplete="off"
						autocapitalize="characters" spellcheck="false" required
						placeholder="BCK-2026-XXXXXXXX" inputmode="text">
					<p class="bck-field__hint"><?php p($l->t('Scan the QR payload (BCK1:…) or type the order reference printed on the ticket (BCK-YYYY-XXXXXXXX).')); ?></p>
					<p class="bck-field-error" id="bck-scan-error" role="alert" hidden></p>
				</div>
				<div class="bck-form-actions">
					<button type="submit" class="bck-btn bck-btn--primary"><?php p($l->t('Look up')); ?></button>
				</div>
			</form>
			<div id="bck-scan-result" class="bck-callout" hidden></div>
		</div>
	</section>
</div>
<?php include __DIR__ . '/../common/page-end.php'; ?>

<?php
/** @var array $_ @var \OCP\IL10N $l — Settings → Invoicing & tax */
?>
<form class="bck-card bck-settings-form" data-section="invoicing" novalidate>
	<div class="bck-card__body">
	<h3 class="bck-card__title"><?php p($l->t('Your company (invoices)')); ?></h3>
	<div class="bck-field">
		<label for="bck-company-name"><?php p($l->t('Name')); ?></label>
		<input id="bck-company-name" type="text" value="<?php p((string)$_['companyName']); ?>" maxlength="190">
	</div>
	<div class="bck-field">
		<label for="bck-company-address"><?php p($l->t('Address')); ?></label>
		<textarea id="bck-company-address" rows="3" maxlength="1000"><?php p((string)$_['companyAddress']); ?></textarea>
	</div>
	<div class="bck-field">
		<label for="bck-seller-email"><?php p($l->t('Notification email')); ?></label>
		<input id="bck-seller-email" type="email" value="<?php p((string)($_['sellerEmail'] ?? '')); ?>" maxlength="190" data-field="seller_email">
		<p class="settings-hint"><?php p($l->t('Order and payment notifications go to this address.')); ?></p>
	</div>
	<div class="bck-field">
		<label for="bck-invoice-prefix"><?php p($l->t('Invoice number prefix')); ?></label>
		<input id="bck-invoice-prefix" type="text" value="<?php p((string)($_['invoicePrefix'] ?? '')); ?>" maxlength="20" placeholder="INV" data-field="invoice_prefix">
	</div>
	<div class="bck-field">
		<label for="bck-company-vat"><?php p($l->t('VAT ID')); ?></label>
		<input id="bck-company-vat" type="text" value="<?php p((string)$_['companyVatId']); ?>" maxlength="30">
	</div>
	<div class="bck-field">
		<label for="bck-company-country"><?php p($l->t('Country')); ?></label>
		<input id="bck-company-country" type="text" value="<?php p((string)$_['companyCountry']); ?>"
			maxlength="2" placeholder="DE" style="text-transform: uppercase;">
	</div>
	<p>
		<label class="bck-check">
			<input type="checkbox" id="bck-kleinunternehmer" <?php if ((string)$_['kleinunternehmer'] === '1') { p('checked'); } ?>>
			<span><?php p($l->t('Small-business exemption (§19 UStG) — invoices carry no VAT')); ?></span>
		</label>
	</p>
	<p>
		<label class="bck-check">
			<input type="checkbox" id="bck-vies-online" <?php if ((string)$_['viesOnline'] === '1') { p('checked'); } ?>>
			<span><?php p($l->t('Verify buyer VAT IDs live against VIES (Pro)')); ?></span>
		</label>
	</p>
	<p class="settings-hint">
		<?php p($l->t('With live verification, valid cross-border EU VAT IDs bill net (reverse charge). Without it, VAT IDs are stored on the invoice but VAT is always charged.')); ?>
	</p>
	<p>
		<label class="bck-check">
			<input type="checkbox" id="bck-oss" <?php if ((string)$_['ossEnabled'] === '1') { p('checked'); } ?>>
			<span><?php p($l->t('OSS VAT rates apply (one-stop shop)')); ?></span>
		</label>
	</p>
	<div class="bck-field">
		<label for="bck-oss-map"><?php p($l->t('OSS VAT map (JSON: country → basis points)')); ?></label>
		<textarea id="bck-oss-map" rows="2" maxlength="2000" placeholder='{"DE":1900,"AT":2000}'><?php p((string)$_['ossVatMap']); ?></textarea>
	</div>
	<div class="bck-field">
		<label for="bck-datev-consultant"><?php p($l->t('DATEV consultant number')); ?></label>
		<input id="bck-datev-consultant" type="text" value="<?php p((string)$_['datevConsultant']); ?>" maxlength="7" inputmode="numeric">
	</div>
	<div class="bck-field">
		<label for="bck-datev-client"><?php p($l->t('DATEV client number')); ?></label>
		<input id="bck-datev-client" type="text" value="<?php p((string)$_['datevClient']); ?>" maxlength="5" inputmode="numeric">
	</div>
	<div class="bck-field">
		<label for="bck-datev-revenue"><?php p($l->t('DATEV revenue account')); ?></label>
		<input id="bck-datev-revenue" type="text" value="<?php p((string)$_['datevRevenue']); ?>" maxlength="10" inputmode="numeric">
	</div>
	<div class="bck-field">
		<label for="bck-datev-receivable"><?php p($l->t('DATEV receivable account')); ?></label>
		<input id="bck-datev-receivable" type="text" value="<?php p((string)$_['datevReceivable']); ?>" maxlength="10" inputmode="numeric">
	</div>
	<p>
		<button type="submit" class="bck-btn bck-btn--primary"><?php p($l->t('Save settings')); ?></button>
		<span class="bck-settings-status" role="status" aria-live="polite"></span>
	</p>
	</div>
</form>

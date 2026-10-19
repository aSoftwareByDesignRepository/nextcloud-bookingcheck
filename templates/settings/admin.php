<?php
/** Admin settings section — PSP credentials, company data, license, portal. */
?>
<div id="bck-admin-settings" class="section">
	<h2><?php p($l->t('BookingCheck')); ?></h2>

	<h3><?php p($l->t('Payments')); ?></h3>
	<p class="settings-hint"><?php p($l->t('Which payment provider processes checkouts. Webhooks confirm payment — the redirect is never trusted.')); ?></p>
	<div class="bck-field">
		<label for="bck-psp-provider"><?php p($l->t('Provider')); ?></label>
		<select id="bck-psp-provider">
			<option value="mollie" <?php if (($_['pspProvider'] ?? '') === 'mollie') { p('selected'); } ?>>Mollie</option>
			<option value="stripe" <?php if (($_['pspProvider'] ?? '') === 'stripe') { p('selected'); } ?>>Stripe</option>
		</select>
	</div>
	<div class="bck-field">
		<label for="bck-mollie-key"><?php p($l->t('Mollie API key')); ?></label>
		<input id="bck-mollie-key" type="password" autocomplete="off"
			placeholder="<?php p($_['hasMollieKey'] ? '••••••••' : $l->t('live_… or test_…')); ?>">
	</div>
	<div class="bck-field">
		<label for="bck-stripe-key"><?php p($l->t('Stripe secret key')); ?></label>
		<input id="bck-stripe-key" type="password" autocomplete="off"
			placeholder="<?php p($_['hasStripeKey'] ? '••••••••' : 'sk_live_…'); ?>">
	</div>

	<h3><?php p($l->t('Your company (invoices)')); ?></h3>
	<div class="bck-field">
		<label for="bck-company-name"><?php p($l->t('Name')); ?></label>
		<input id="bck-company-name" type="text" value="<?php p((string)$_['companyName']); ?>" maxlength="190">
	</div>
	<div class="bck-field">
		<label for="bck-company-address"><?php p($l->t('Address')); ?></label>
		<textarea id="bck-company-address" rows="3" maxlength="1000"><?php p((string)$_['companyAddress']); ?></textarea>
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

	<h3><?php p($l->t('License')); ?></h3>
	<p class="settings-hint">
		<?php p($l->t('Current plan:') . ' ' . (string)$_['licenseState']); ?>
	</p>
	<div class="bck-field">
		<label for="bck-license-key"><?php p($l->t('License key')); ?></label>
		<input id="bck-license-key" type="text" value="<?php p((string)$_['licenseKey']); ?>"
			placeholder="BCK-…" maxlength="512">
	</div>

	<h3><?php p($l->t('Public features')); ?></h3>
	<p>
		<label class="bck-check">
			<input type="checkbox" id="bck-directory" <?php if ((string)$_['directoryEnabled'] === '1') { p('checked'); } ?>>
			<span><?php p($l->t('Public directory feed (read-only catalog JSON)')); ?></span>
		</label>
	</p>
	<p>
		<label class="bck-check">
			<input type="checkbox" id="bck-portal-enabled" <?php if ((string)$_['portalEnabled'] === '1') { p('checked'); } ?>>
			<span><?php p($l->t('Customer portal (passwordless sign-in)')); ?></span>
		</label>
	</p>
	<div class="bck-field">
		<label for="bck-cors"><?php p($l->t('Allowed feed origins (comma-separated)')); ?></label>
		<input id="bck-cors" type="text" value="<?php p((string)$_['corsOrigins']); ?>"
			placeholder="https://example.com">
	</div>

	<h3><?php p($l->t('Notifications')); ?></h3>
	<p>
		<label class="bck-check">
			<input type="checkbox" id="bck-notify-paid" <?php if ((string)$_['notifyPaid'] === '1') { p('checked'); } ?>>
			<span><?php p($l->t('Notify me when a paid order arrives')); ?></span>
		</label>
	</p>

	<?php if (!empty($_['crmAvailable'])): ?>
	<h3><?php p($l->t('Integrations')); ?></h3>
	<p>
		<label class="bck-check">
			<input type="checkbox" id="bck-crm-sync" <?php if ((string)$_['crmSyncEnabled'] === '1') { p('checked'); } ?>>
			<span><?php p($l->t('Send buyers to CustomerCheck (CRM)')); ?></span>
		</label>
	</p>
	<div class="bck-field">
		<label for="bck-crm-token"><?php p($l->t('CustomerCheck order-hook token')); ?></label>
		<input id="bck-crm-token" type="password" autocomplete="off"
			placeholder="<?php p($_['hasCrmToken'] ? '••••••••' : $l->t('Paste token from CustomerCheck settings')); ?>">
	</div>
	<p class="settings-hint">
		<?php p($l->t('Buyers become CRM contacts after payment. Attendees are never synced.')); ?>
	</p>
	<?php endif; ?>

	<p>
		<button id="bck-settings-save" class="bck-btn bck-btn--primary"><?php p($l->t('Save settings')); ?></button>
		<span id="bck-settings-status" role="status" aria-live="polite"></span>
	</p>
</div>

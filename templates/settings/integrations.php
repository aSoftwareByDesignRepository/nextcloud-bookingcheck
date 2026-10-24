<?php
/** @var array $_ @var \OCP\IL10N $l — Settings → Integrations */
?>
<form class="bck-card bck-settings-form" data-section="integrations" novalidate>
	<div class="bck-card__body">
	<p>
		<label class="bck-checkbox">
			<input type="checkbox" id="bck-av" <?php if ((string)$_['avEnabled'] === '1') { p('checked'); } ?>>
			<span><?php p($l->t('Virus-scan attendee uploads (ClamAV/ICAP)')); ?></span>
		</label>
	</p>
	<div class="bck-field">
		<label for="bck-av-endpoint"><?php p($l->t('ICAP endpoint')); ?></label>
		<input id="bck-av-endpoint" type="text" value="<?php p((string)$_['avIcapEndpoint']); ?>"
			placeholder="icap://127.0.0.1:1344/srv_clamav" maxlength="190">
	</div>

	<h4><?php p($l->t('Wallet passes (optional)')); ?></h4>
	<p class="settings-hint">
		<?php p($l->t('PDF and QR tickets always work — wallet passes are an extra for buyers with Apple or Google Wallet. Leave empty to hide the buttons.')); ?>
	</p>
	<div class="bck-field">
		<label for="bck-wallet-google-issuer"><?php p($l->t('Google Wallet issuer ID')); ?></label>
		<input id="bck-wallet-google-issuer" type="text" value="<?php p((string)$_['walletGoogleIssuer']); ?>" maxlength="64">
	</div>
	<div class="bck-field">
		<label for="bck-wallet-google-sa"><?php p($l->t('Google service-account email')); ?></label>
		<input id="bck-wallet-google-sa" type="text" value="<?php p((string)$_['walletGoogleSaEmail']); ?>" maxlength="190">
	</div>
	<div class="bck-field">
		<label for="bck-wallet-google-secret"><?php p($l->t('Google Wallet secret')); ?></label>
		<input id="bck-wallet-google-secret" type="password" autocomplete="off"
			placeholder="<?php p($_['hasWalletGoogleSecret'] ? '••••••••' : $l->t('Paste the shared secret')); ?>">
	</div>
	<div class="bck-field">
		<label for="bck-wallet-apple-ptid"><?php p($l->t('Apple pass type ID')); ?></label>
		<input id="bck-wallet-apple-ptid" type="text" value="<?php p((string)$_['walletApplePassTypeId']); ?>"
			placeholder="pass.de.example.booking" maxlength="190">
	</div>
	<div class="bck-field">
		<label for="bck-wallet-apple-team"><?php p($l->t('Apple team ID')); ?></label>
		<input id="bck-wallet-apple-team" type="text" value="<?php p((string)$_['walletAppleTeamId']); ?>" maxlength="10">
	</div>
	<div class="bck-field">
		<label for="bck-wallet-apple-cert"><?php p($l->t('Apple pass certificate (PEM)')); ?></label>
		<textarea id="bck-wallet-apple-cert" rows="3" maxlength="8000"
			placeholder="<?php p($_['hasWalletAppleCert'] ? '••••••••' : '-----BEGIN CERTIFICATE-----'); ?>"></textarea>
	</div>
	<div class="bck-field">
		<label for="bck-wallet-apple-key"><?php p($l->t('Apple pass private key (PEM)')); ?></label>
		<textarea id="bck-wallet-apple-key" rows="3" maxlength="8000"
			placeholder="<?php p($_['hasWalletAppleKey'] ? '••••••••' : '-----BEGIN PRIVATE KEY-----'); ?>"></textarea>
	</div>

	<?php if (!empty($_['crmAvailable'])): ?>
	<h4><?php p($l->t('CustomerCheck')); ?></h4>
	<p>
		<label class="bck-checkbox">
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
		<button type="submit" class="bck-btn bck-btn--primary"><?php p($l->t('Save settings')); ?></button>
		<span class="bck-settings-status" role="status" aria-live="polite"></span>
	</p>
	</div>
</form>

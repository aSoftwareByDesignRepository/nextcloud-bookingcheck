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
			<option value="sumup" <?php if (($_['pspProvider'] ?? '') === 'sumup') { p('selected'); } ?>>SumUp</option>
			<option value="paypal" <?php if (($_['pspProvider'] ?? '') === 'paypal') { p('selected'); } ?>>PayPal</option>
			<option value="klarna" <?php if (($_['pspProvider'] ?? '') === 'klarna') { p('selected'); } ?>>Klarna</option>
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
	<div class="bck-field">
		<label for="bck-mollie-test"><?php p($l->t('Mollie test key')); ?></label>
		<input id="bck-mollie-test" type="password" autocomplete="off"
			placeholder="<?php p($_['hasMollieTestKey'] ? '••••••••' : 'test_…'); ?>">
	</div>
	<div class="bck-field">
		<label for="bck-mollie-webhook"><?php p($l->t('Mollie webhook secret')); ?></label>
		<input id="bck-mollie-webhook" type="password" autocomplete="off"
			placeholder="<?php p($_['hasMollieWebhookSecret'] ? '••••••••' : 'whsec_…'); ?>">
	</div>
	<div class="bck-field">
		<label for="bck-stripe-webhook"><?php p($l->t('Stripe webhook secret')); ?></label>
		<input id="bck-stripe-webhook" type="password" autocomplete="off"
			placeholder="<?php p($_['hasStripeWebhookSecret'] ? '••••••••' : 'whsec_…'); ?>">
	</div>
	<div class="bck-field">
		<label for="bck-sumup-key"><?php p($l->t('SumUp API key')); ?></label>
		<input id="bck-sumup-key" type="password" autocomplete="off"
			placeholder="<?php p($_['hasSumupKey'] ? '••••••••' : 'sup_sk_…'); ?>">
	</div>
	<div class="bck-field">
		<label for="bck-sumup-merchant"><?php p($l->t('SumUp merchant code')); ?></label>
		<input id="bck-sumup-merchant" type="text" value="<?php p((string)$_['sumupMerchant']); ?>" maxlength="64">
	</div>
	<div class="bck-field">
		<label for="bck-paypal-id"><?php p($l->t('PayPal client ID')); ?></label>
		<input id="bck-paypal-id" type="text" value="<?php p((string)$_['paypalClientId']); ?>" maxlength="190">
	</div>
	<div class="bck-field">
		<label for="bck-paypal-secret"><?php p($l->t('PayPal client secret')); ?></label>
		<input id="bck-paypal-secret" type="password" autocomplete="off"
			placeholder="<?php p($_['hasPaypalSecret'] ? '••••••••' : $l->t('Paste from the PayPal developer console')); ?>">
	</div>
	<p>
		<label class="bck-check">
			<input type="checkbox" id="bck-paypal-sandbox" <?php if ((string)$_['paypalSandbox'] === '1') { p('checked'); } ?>>
			<span><?php p($l->t('PayPal sandbox mode')); ?></span>
		</label>
	</p>
	<div class="bck-field">
		<label for="bck-klarna-uid"><?php p($l->t('Klarna API UID')); ?></label>
		<input id="bck-klarna-uid" type="text" value="<?php p((string)$_['klarnaUid']); ?>" maxlength="190">
	</div>
	<div class="bck-field">
		<label for="bck-klarna-secret"><?php p($l->t('Klarna API password')); ?></label>
		<input id="bck-klarna-secret" type="password" autocomplete="off"
			placeholder="<?php p($_['hasKlarnaSecret'] ? '••••••••' : 'klarna_live_…'); ?>">
	</div>
	<p>
		<label class="bck-check">
			<input type="checkbox" id="bck-klarna-sandbox" <?php if ((string)$_['klarnaSandbox'] === '1') { p('checked'); } ?>>
			<span><?php p($l->t('Klarna sandbox mode')); ?></span>
		</label>
	</p>

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

	<h3><?php p($l->t('Booking flow')); ?></h3>
	<div class="bck-field">
		<label for="bck-order-hold"><?php p($l->t('Order hold (minutes)')); ?></label>
		<input id="bck-order-hold" type="number" min="1" max="525600" value="<?php p((string)$_['orderHoldMin']); ?>">
	</div>
	<div class="bck-field">
		<label for="bck-approval-hold"><?php p($l->t('Approval hold (minutes)')); ?></label>
		<input id="bck-approval-hold" type="number" min="1" max="525600" value="<?php p((string)$_['approvalHoldMin']); ?>">
	</div>
	<div class="bck-field">
		<label for="bck-payment-ttl"><?php p($l->t('Payment link valid (minutes)')); ?></label>
		<input id="bck-payment-ttl" type="number" min="1" max="525600" value="<?php p((string)$_['paymentTtlMin']); ?>">
	</div>
	<div class="bck-field">
		<label for="bck-otp-ttl"><?php p($l->t('Sign-in code valid (minutes)')); ?></label>
		<input id="bck-otp-ttl" type="number" min="1" max="525600" value="<?php p((string)$_['otpTtlMin']); ?>">
	</div>
	<div class="bck-field">
		<label for="bck-portal-sess"><?php p($l->t('Portal session (days)')); ?></label>
		<input id="bck-portal-sess" type="number" min="1" max="525600" value="<?php p((string)$_['portalSessDays']); ?>">
	</div>
	<div class="bck-field">
		<label for="bck-waitlist-offer"><?php p($l->t('Waitlist offer valid (hours)')); ?></label>
		<input id="bck-waitlist-offer" type="number" min="1" max="525600" value="<?php p((string)$_['waitlistOfferH']); ?>">
	</div>

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
	<div class="bck-field">
		<label for="bck-ical-token"><?php p($l->t('Calendar feed token')); ?></label>
		<input id="bck-ical-token" type="text" value="<?php p((string)$_['icalFeedToken']); ?>" maxlength="64">
		<?php if ((string)$_['icalFeedUrl'] !== ''): ?>
			<p class="settings-hint"><?php p($l->t('Feed URL:')) . ' ' . (string)$_['icalFeedUrl']; ?></p>
		<?php endif; ?>
	</div>
	<div class="bck-field">
		<label for="bck-default-locale"><?php p($l->t('Default language')); ?></label>
		<select id="bck-default-locale">
			<option value="" <?php if ((string)$_['defaultLocale'] === '') { p('selected'); } ?>><?php p($l->t('Buyer browser language')); ?></option>
			<option value="en" <?php if ((string)$_['defaultLocale'] === 'en') { p('selected'); } ?>>English</option>
			<option value="de" <?php if ((string)$_['defaultLocale'] === 'de') { p('selected'); } ?>>Deutsch</option>
		</select>
	</div>

	<h3><?php p($l->t('Notifications')); ?></h3>
	<p>
		<label class="bck-check">
			<input type="checkbox" id="bck-notify-paid" <?php if ((string)$_['notifyPaid'] === '1') { p('checked'); } ?>>
			<span><?php p($l->t('Notify me when a paid order arrives')); ?></span>
		</label>
	</p>

	<h3><?php p($l->t('Integrations')); ?></h3>
	<p>
		<label class="bck-check">
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

	<h3><?php p($l->t('Email texts')); ?></h3>
	<p class="settings-hint">
		<?php p($l->t('Adjust the subject and add your own text to automatic emails. Empty fields keep the built-in text. Leave the language empty to use it for all languages.')); ?>
	</p>
	<div class="bck-field">
		<label for="bck-mailtpl-kind"><?php p($l->t('Email')); ?></label>
		<select id="bck-mailtpl-kind">
			<?php foreach ($_['mailKindLabels'] as $k): ?>
				<option value="<?php p($k[0]); ?>"><?php p($k[1]); ?></option>
			<?php endforeach; ?>
		</select>
	</div>
	<div class="bck-field">
		<label for="bck-mailtpl-locale"><?php p($l->t('Language')); ?></label>
		<select id="bck-mailtpl-locale">
			<option value=""><?php p($l->t('All languages')); ?></option>
			<option value="en">English</option>
			<option value="de">Deutsch</option>
		</select>
	</div>
	<div class="bck-field">
		<label for="bck-mailtpl-subject"><?php p($l->t('Subject')); ?></label>
		<input id="bck-mailtpl-subject" type="text" maxlength="190">
	</div>
	<div class="bck-field">
		<label for="bck-mailtpl-extra"><?php p($l->t('Extra text')); ?></label>
		<textarea id="bck-mailtpl-extra" rows="3" maxlength="4000"></textarea>
	</div>
	<p>
		<button id="bck-mailtpl-save" class="bck-btn bck-btn--primary"><?php p($l->t('Save email text')); ?></button>
		<span id="bck-mailtpl-status" role="status" aria-live="polite"></span>
	</p>
	<?php if (!empty($_['mailTemplates'])): ?>
		<h4><?php p($l->t('Saved email texts')); ?></h4>
		<ul id="bck-mailtpl-list" class="bck-list">
			<?php foreach ($_['mailTemplates'] as $t): ?>
				<li>
					<strong><?php p($t['kind']); ?></strong>
					<?php if ($t['locale'] !== ''): ?>(<?php p($t['locale']); ?>)<?php endif; ?>
					<?php if ($t['subject'] !== ''): ?> — <?php p($t['subject']); ?><?php endif; ?>
					<button type="button" class="bck-btn bck-btn--secondary bck-mailtpl-del"
						data-id="<?php p($t['id']); ?>"><?php p($l->t('Delete')); ?></button>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<p>
		<button id="bck-settings-save" class="bck-btn bck-btn--primary"><?php p($l->t('Save settings')); ?></button>
		<span id="bck-settings-status" role="status" aria-live="polite"></span>
	</p>
</div>

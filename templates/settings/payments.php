<?php
/** @var array $_ @var \OCP\IL10N $l — Settings → Payments */
?>
<form class="bck-card bck-settings-form" data-section="payments" novalidate>
	<div class="bck-card__body">
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
		<label class="bck-checkbox">
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
		<label class="bck-checkbox">
			<input type="checkbox" id="bck-klarna-sandbox" <?php if ((string)$_['klarnaSandbox'] === '1') { p('checked'); } ?>>
			<span><?php p($l->t('Klarna sandbox mode')); ?></span>
		</label>
	</p>
	<p>
		<button type="submit" class="bck-btn bck-btn--primary"><?php p($l->t('Save settings')); ?></button>
		<span class="bck-settings-status" role="status" aria-live="polite"></span>
	</p>
	</div>
</form>

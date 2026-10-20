<?php
/** Checkout — the shortest possible path: pick a date, who, how many, pay. */
\OCA\BookingCheck\Support\AssetRegistrar::registerPublic('public-checkout');
$o = $_['offering'] ?? [];
$occurrences = $_['occurrences'] ?? [];
$priceCats = $_['priceCats'] ?? [];
$addons = $_['addons'] ?? [];
$tosText = trim((string)($o['tosText'] ?? ''));
$currency = (string)($o['currency'] ?? 'EUR');
$isPass = ($_['isPass'] ?? false) === true;
$invoicePay = ($_['invoicePay'] ?? false) === true;
$depositBp = (int)($_['depositBp'] ?? 0);
$fmt = static function (int $cents) use ($currency): string {
	return $currency . ' ' . number_format($cents / 100, 2, ',', '.');
};
?>
<div class="bck-pub bck-pub--narrow">
	<h1 class="bck-pub__title"><?php p((string)$o['title']); ?></h1>
	<p class="bck-pub__lead"><?php p($_['approvalRequired']
		? $l->t('Send your request — we confirm by email.')
		: $l->t('Almost done — pick a date and tell us who is booking.')); ?></p>

	<?php if ($occurrences === [] && !$isPass): ?>
	<div class="bck-card">
		<div class="bck-card__body">
			<div class="bck-callout bck-callout--info" role="status">
				<p class="bck-callout__text"><?php p($l->t('No dates are bookable right now. Please check back later.')); ?></p>
			</div>
		</div>
	</div>
	<?php else: ?>
	<form id="bck-checkout" class="bck-card bck-form" novalidate
		data-bck-offering-id="<?php p((int)$o['id']); ?>"
		data-bck-approval="<?php p($_['approvalRequired'] ? '1' : '0'); ?>"
		data-bck-pass="<?php p($isPass ? '1' : '0'); ?>"
		data-bck-unit="<?php p((int)$o['priceCents']); ?>"
		data-bck-currency="<?php p($currency); ?>">

		<div class="bck-card__body">
			<?php if ($isPass): ?>
			<div class="bck-callout bck-callout--info" role="status">
				<p class="bck-callout__text"><?php p($l->t('This is a membership — it is valid right away and does not need a date.')); ?></p>
			</div>
			<?php else: ?>
			<fieldset class="bck-field bck-fieldset">
				<legend class="bck-label"><?php p($l->t('Pick a date')); ?></legend>
				<div class="bck-occ-list" role="radiogroup">
					<?php $picked = false; ?>
					<?php foreach ($occurrences as $occ):
						// a pooled slot is bookable only through a pool —
						// render each pool as its own flat pick so the buyer
						// sees "Mon 10:00 · Room A — 3 left" honestly
						$rows = [];
						$pools = $occ['resources'] ?? [];
						if ($occ['target_type'] === 'slot' && $pools !== []) {
							foreach ($pools as $pool) {
								$rows[] = [
									'value' => 'slot:' . (int)$occ['id'] . ':' . (int)$pool['id'],
									'label' => (string)$occ['label'] . ' · ' . (string)$pool['name'],
									'starts_at' => $occ['starts_at'],
									'available' => (int)$pool['available'],
									'series' => false,
								];
							}
						} else {
							$rows[] = [
								'value' => $occ['target_type'] . ':' . (int)$occ['id'],
								'label' => $occ['label'],
								'starts_at' => $occ['starts_at'],
								'available' => (int)$occ['available'],
								'series' => $occ['target_type'] === 'series',
							];
						}
						foreach ($rows as $row):
							$soldOut = $row['available'] <= 0;
							$preselect = !$soldOut && !$picked;
							if ($preselect) { $picked = true; }
					?>
					<label class="bck-occ<?php if ($soldOut) { p(' bck-occ--soldout'); } ?>">
						<input type="radio" name="occ" value="<?php p($row['value']); ?>"
							<?php if ($soldOut) { p('disabled'); } elseif ($preselect) { p('checked'); } ?>
							data-available="<?php p($row['available']); ?>">
						<span class="bck-occ__label">
							<?php if ($row['series']): ?>
								<?php p((string)($occ['label'] ?? $l->t('Course series'))); ?>
							<?php else: ?>
								<time datetime="<?php p((string)$row['starts_at']); ?>">
									<?php p((string)$row['label']); ?>
								</time>
							<?php endif; ?>
						</span>
						<span class="bck-occ__avail">
							<?php if ($soldOut): ?>
								<?php p($l->t('Sold out')); ?>
							<?php else: ?>
								<?php p($l->t('%s left', [(string)$row['available']])); ?>
							<?php endif; ?>
						</span>
					</label>
					<?php endforeach; endforeach; ?>
				</div>
				<p class="bck-field-error" id="bck-lines-error" hidden></p>
			</fieldset>

			<?php if ($priceCats !== []): ?>
			<div class="bck-field">
				<label for="bck-cat" class="bck-label"><?php p($l->t('Ticket type')); ?></label>
				<select id="bck-cat" name="price_cat_id" class="bck-select">
					<option value=""><?php p($l->t('Standard')); ?> — <?php p($fmt((int)$o['priceCents'])); ?></option>
					<?php foreach ($priceCats as $cat): ?>
					<option value="<?php p((int)$cat['id']); ?>" data-price="<?php p((int)$cat['priceCents']); ?>">
						<?php p((string)$cat['name']); ?> — <?php p($fmt((int)$cat['priceCents'])); ?>
					</option>
					<?php endforeach; ?>
				</select>
				<p class="bck-field-error" id="bck-price_cat_id-error" hidden></p>
			</div>
			<?php endif; ?>
			<?php endif; // !isPass — end of date + ticket-type section ?>

			<div class="bck-field">
				<label for="bck-qty" class="bck-label"><?php p($isPass
					? $l->t('How many memberships?')
					: $l->t('How many seats?')); ?></label>
				<input id="bck-qty" name="qty" type="number" min="1" max="50" value="1" required inputmode="numeric">
				<p class="bck-field-error" id="bck-qty-error" hidden></p>
			</div>

			<?php if ($addons !== []): ?>
			<fieldset class="bck-field bck-fieldset">
				<legend class="bck-label"><?php p($l->t('Extras')); ?> <span class="bck-label__opt">(<?php p($l->t('optional')); ?>)</span></legend>
				<?php foreach ($addons as $ad): ?>
				<label class="bck-check bck-addon">
					<input type="checkbox" name="addon_<?php p((int)$ad['id']); ?>" value="1"
						data-addon-id="<?php p((int)$ad['id']); ?>" data-price="<?php p((int)$ad['priceCents']); ?>">
					<span class="bck-check__text"><?php p((string)$ad['name']); ?></span>
					<span class="bck-occ__avail"><?php p($fmt((int)$ad['priceCents'])); ?></span>
				</label>
				<?php endforeach; ?>
				<p class="bck-field-error" id="bck-addons-error" hidden></p>
			</fieldset>
			<?php endif; ?>

			<div class="bck-field">
				<label for="bck-name" class="bck-label"><?php p($l->t('Your name')); ?></label>
				<input id="bck-name" name="buyer_name" type="text" autocomplete="name" required minlength="2" maxlength="190">
				<p class="bck-field-error" id="bck-buyer_name-error" hidden></p>
			</div>

			<div class="bck-field">
				<label for="bck-email" class="bck-label"><?php p($l->t('Email')); ?></label>
				<input id="bck-email" name="buyer_email" type="email" autocomplete="email" required maxlength="190">
				<p class="bck-field__hint"><?php p($l->t('Tickets and your manage link go here.')); ?></p>
				<p class="bck-field-error" id="bck-buyer_email-error" hidden></p>
			</div>

			<details class="bck-details bck-billing">
				<summary class="bck-details__summary"><?php p($l->t('Booking for a business?')); ?></summary>
				<div class="bck-details__body">
					<div class="bck-field">
						<label for="bck-company" class="bck-label"><?php p($l->t('Company name')); ?></label>
						<input id="bck-company" name="buyer_company" type="text" autocomplete="organization" maxlength="190">
						<p class="bck-field-error" id="bck-buyer_company-error" hidden></p>
					</div>
					<div class="bck-field">
						<label for="bck-bcountry" class="bck-label"><?php p($l->t('Billing country')); ?></label>
						<select id="bck-bcountry" name="billing_country" class="bck-select">
							<option value=""><?php p($l->t('Choose…')); ?></option>
							<?php foreach (['AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE','IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE','CH','GB','LI','NO','US'] as $cc): ?>
							<option value="<?php p($cc); ?>"><?php p($cc); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="bck-field-error" id="bck-billing_country-error" hidden></p>
					</div>
					<div class="bck-field">
						<label for="bck-vat" class="bck-label"><?php p($l->t('VAT ID')); ?>
							<span class="bck-label__opt">(<?php p($l->t('optional')); ?>)</span></label>
						<input id="bck-vat" name="vat_id" type="text" autocomplete="off" maxlength="15"
							placeholder="DE123456789">
						<p class="bck-field__hint"><?php p($l->t('EU businesses: we verify it and bill without VAT when valid.')); ?></p>
						<p class="bck-field-error" id="bck-vat_id-error" hidden></p>
					</div>
				</div>
			</details>

			<div class="bck-field">
				<label for="bck-promo" class="bck-label"><?php p($l->t('Voucher or promo code')); ?>
					<span class="bck-label__opt">(<?php p($l->t('optional')); ?>)</span></label>
				<div class="bck-inline-form">
					<input id="bck-promo" name="promo" type="text" autocomplete="off" maxlength="32">
					<button type="button" class="bck-btn bck-btn--secondary" id="bck-promo-apply"><?php p($l->t('Apply')); ?></button>
				</div>
				<p class="bck-field__hint" id="bck-promo-hint"></p>
				<p class="bck-field-error" id="bck-promo-error" hidden></p>
			</div>

			<?php if ($invoicePay): ?>
			<fieldset class="bck-field bck-fieldset">
				<legend class="bck-label"><?php p($l->t('How do you want to pay?')); ?></legend>
				<label class="bck-occ">
					<input type="radio" name="pay_method" value="psp" checked>
					<span class="bck-occ__label"><?php p($l->t('Pay now online')); ?></span>
				</label>
				<label class="bck-occ">
					<input type="radio" name="pay_method" value="invoice">
					<span class="bck-occ__label"><?php p($l->t('Pay later by invoice')); ?></span>
				</label>
				<p class="bck-field__hint"><?php p($l->t('With invoice you get your tickets now and pay after the bill arrives.')); ?></p>
			</fieldset>
			<?php endif; ?>

			<?php if ($depositBp > 0 && $depositBp < 10000): ?>
			<div class="bck-callout bck-callout--info" role="status">
				<p class="bck-callout__text"><?php p($l->t('You pay %s%% now — the rest is due later.', [(string)intdiv($depositBp, 100)])); ?></p>
			</div>
			<?php endif; ?>

			<?php /* honeypot — bots fill hidden fields, humans never see it */ ?>
			<input type="text" name="hp_website" tabindex="-1" autocomplete="off"
				class="bck-hp" aria-hidden="true">

			<?php if ($tosText !== ''): ?>
			<div class="bck-field bck-field--check">
				<label class="bck-check">
					<input id="bck-consent" name="consent" type="checkbox" value="1" required
						aria-describedby="bck-consent-error">
					<span class="bck-check__text"><?php p($l->t('I accept the terms')); ?></span>
				</label>
				<p class="bck-field__hint"><?php p($tosText); ?></p>
				<p class="bck-field-error" id="bck-consent-error" hidden></p>
			</div>
			<?php endif; ?>
		</div>

		<div class="bck-card__footer">
			<div class="bck-checkout-total" id="bck-total" aria-live="polite"></div>
			<button type="submit" class="bck-btn bck-btn--primary bck-btn--xl" id="bck-pay-btn">
				<?php p($_['approvalRequired'] ? $l->t('Send request') : $l->t('Continue to payment')); ?></button>
			<p class="bck-field__hint"><?php p($l->t('You can review everything before paying.')); ?></p>
		</div>
	</form>
	<?php endif; ?>
</div>

<?php
/** @var \OCP\IL10N $l */
$isNew = empty($_['offering']);
$_['pageTitle'] = $isNew ? $l->t('New offering') : $l->t('Edit offering');
$_['pageHelp'] = $l->t('Title, price, and how it is sold. Everything else can wait.');
$_['pageIcon'] = 'calendar';
\OCA\BookingCheck\Support\Chrome::apply($_, 'offerings');
include __DIR__ . '/../common/page-start.php';

$o = $_['offering'] ?? [];
$oid = (int)($_['offeringId'] ?? $o['id'] ?? 0);
$url = \OCP\Server::get(\OCP\IURLGenerator::class);
$action = $isNew
	? $url->linkToRoute('bookingcheck.offering.store')
	: $url->linkToRoute('bookingcheck.offering.update', ['id' => $oid]);
?>
<div class="bck-page-stack bck-page-stack--form">
	<form class="bck-form" method="post" action="<?php p($action); ?>"
		data-bck-form="offering" data-bck-method="<?php p($isNew ? 'POST' : 'PUT'); ?>" novalidate>
		<fieldset class="bck-fieldset">
			<legend class="bck-fieldset__legend"><?php p($l->t('The basics')); ?></legend>
			<div class="bck-field">
				<label for="bck-title" class="bck-label"><?php p($l->t('Title')); ?></label>
				<input id="bck-title" name="title" type="text" required maxlength="190"
					value="<?php p((string)($o['title'] ?? '')); ?>">
				<p class="bck-field-error" id="bck-title-error" hidden></p>
			</div>
			<div class="bck-field-row">
				<div class="bck-field">
					<label for="bck-type" class="bck-label"><?php p($l->t('How is it sold?')); ?></label>
					<select id="bck-type" name="type">
						<?php $types = ['event' => $l->t('Single event'), 'course' => $l->t('Course / series'), 'timeslot' => $l->t('Bookable timeslots')];
						if (!empty($_['pro'])) { $types['pass'] = $l->t('Membership / pass'); }
						foreach ($types as $v => $lbl): ?>
						<option value="<?php p($v); ?>" <?php if (($o['type'] ?? 'event') === $v) { p('selected'); } ?>><?php p($lbl); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="bck-field">
					<label for="bck-price" class="bck-label"><?php p($l->t('Price per seat')); ?></label>
					<input id="bck-price" name="price" type="number" min="0" step="0.01" inputmode="decimal" required
						data-field="price_cents"
						value="<?php p(number_format(((int)($o['priceCents'] ?? 0)) / 100, 2, '.', '')); ?>">
					<p class="bck-field__hint"><?php p($l->t('0.00 makes it a free booking.')); ?></p>
				</div>
				<div class="bck-field">
					<label for="bck-currency" class="bck-label"><?php p($l->t('Currency')); ?></label>
					<input id="bck-currency" name="currency" type="text" minlength="3" maxlength="3" required
						pattern="[A-Za-z]{3}" style="text-transform: uppercase"
						value="<?php p((string)($o['currency'] ?? 'EUR')); ?>">
				</div>
			</div>
			<div class="bck-field">
				<label for="bck-descr" class="bck-label"><?php p($l->t('Description')); ?></label>
				<textarea id="bck-descr" name="descr" rows="4" maxlength="5000"><?php p((string)($o['descr'] ?? '')); ?></textarea>
			</div>
		</fieldset>

		<fieldset class="bck-fieldset">
			<legend class="bck-fieldset__legend"><?php p($l->t('Options')); ?></legend>
			<label class="bck-checkbox">
				<input type="hidden" name="approval_required" value="0">
				<input type="checkbox" name="approval_required" value="1" <?php if ((int)($o['approvalRequired'] ?? 0) === 1) { p('checked'); } ?>>
				<span><?php p($l->t('Bookings need my approval first')); ?></span>
			</label>
			<label class="bck-checkbox" id="bck-bookseries-wrap">
				<input type="hidden" name="book_series" value="0">
				<input type="checkbox" name="book_series" value="1" <?php if ((int)($o['bookSeries'] ?? 0) === 1) { p('checked'); } ?>>
				<span><?php p($l->t('Buyers can book the whole series at once (courses)')); ?></span>
			</label>
			<label class="bck-checkbox">
				<input type="hidden" name="list_in_directory" value="0">
				<input type="checkbox" name="list_in_directory" value="1" <?php if ((int)($o['listInDirectory'] ?? 1) === 1) { p('checked'); } ?>>
				<span><?php p($l->t('Show in the public directory feed')); ?></span>
			</label>
			<div class="bck-field">
				<label for="bck-tos" class="bck-label"><?php p($l->t('Booking terms')); ?>
					<span class="bck-label__opt">(<?php p($l->t('optional')); ?>)</span></label>
				<textarea id="bck-tos" name="tos_text" rows="3" maxlength="5000"><?php p((string)($o['tosText'] ?? '')); ?></textarea>
				<p class="bck-field__hint"><?php p($l->t('Shown as a required checkbox at checkout. The exact wording is stored on every order.')); ?></p>
			</div>
			<div class="bck-field">
				<label for="bck-reminder" class="bck-label"><?php p($l->t('Email reminder (days before)')); ?></label>
				<input id="bck-reminder" name="reminder_days" type="number" min="0" max="365" inputmode="numeric"
					value="<?php p((int)($o['reminderDays'] ?? 0)); ?>">
				<p class="bck-field__hint"><?php p($l->t('0 means no reminder. Buyers get one email that many days before the booked date.')); ?></p>
			</div>
		</fieldset>

		<?php if (!$isNew && (!empty($_['deckAvailable']) || !empty($_['talkAvailable']))): ?>
		<fieldset class="bck-fieldset">
			<legend class="bck-fieldset__legend"><?php p($l->t('Connected apps')); ?></legend>
			<?php if (!empty($_['deckAvailable'])): ?>
			<label class="bck-checkbox">
				<input type="checkbox" data-bck-integration="deck" data-bck-offering="<?php p($oid); ?>"
					<?php if (!empty($_['deckBoardId'])) { p('checked'); } ?>>
				<span><?php p($l->t('Deck board for prep tasks')); ?></span>
			</label>
			<?php endif; ?>
			<?php if (!empty($_['talkAvailable'])): ?>
			<label class="bck-checkbox">
				<input type="checkbox" data-bck-integration="talk" data-bck-offering="<?php p($oid); ?>"
					<?php if (!empty($_['talkRoom'])) { p('checked'); } ?>>
				<span><?php p($l->t('Talk room for attendees (join link shows in their portal)')); ?></span>
			</label>
			<?php endif; ?>
		</fieldset>
		<?php endif; ?>

		<fieldset class="bck-fieldset">
			<div class="bck-field">
				<label for="bck-waitlist" class="bck-label"><?php p($l->t('When sold out')); ?></label>
				<select id="bck-waitlist" name="waitlist_mode" class="bck-select">
					<?php $wl = (string)($o['waitlistMode'] ?? 'allocate'); ?>
					<option value="none" <?php if ($wl === 'none') { p('selected'); } ?>><?php p($l->t('Just show “sold out”')); ?></option>
					<option value="notify" <?php if ($wl === 'notify') { p('selected'); } ?>><?php p($l->t('Waitlist — notify when a seat opens')); ?></option>
					<option value="allocate" <?php if ($wl === 'allocate') { p('selected'); } ?> <?php if (empty($_['pro'])) { p('disabled'); } ?>><?php p($l->t('Waitlist — offer the freed seat for 24 h')); ?><?php if (empty($_['pro'])) { p(' ' . $l->t('(Pro)')); } ?></option>
				</select>
			</div>
		</fieldset>

		<?php if (!empty($_['pro'])): ?>
		<fieldset class="bck-fieldset">
			<legend class="bck-fieldset__legend"><?php p($l->t('Pro features')); ?></legend>
			<label class="bck-checkbox">
				<input type="hidden" name="review_display" value="0">
				<input type="checkbox" name="review_display" value="1" <?php if ((int)($o['reviewDisplay'] ?? 0) === 1) { p('checked'); } ?>>
				<span><?php p($l->t('Buyers can leave a review after attending')); ?></span>
			</label>
			<label class="bck-checkbox">
				<input type="hidden" name="sharing_enabled" value="0">
				<input type="checkbox" name="sharing_enabled" value="1" <?php if ((int)($o['sharingEnabled'] ?? 0) === 1) { p('checked'); } ?>>
				<span><?php p($l->t('Attendees can upload and share files')); ?></span>
			</label>
			<div class="bck-field" id="bck-shscope-field">
				<label for="bck-shscope" class="bck-label"><?php p($l->t('Files are shared with')); ?></label>
				<select id="bck-shscope" name="sharing_scope" class="bck-select">
					<?php $sc = (string)($o['sharingScope'] ?? 'occurrence'); ?>
					<option value="occurrence" <?php if ($sc !== 'offering') { p('selected'); } ?>><?php p($l->t('Attendees of the same date')); ?></option>
					<option value="offering" <?php if ($sc === 'offering') { p('selected'); } ?>><?php p($l->t('All attendees of this offering')); ?></option>
				</select>
			</div>
			<div class="bck-field bck-field--row" id="bck-uploadquota-wrap">
				<div>
					<label for="bck-umax" class="bck-label"><?php p($l->t('Max file size (MB)')); ?></label>
					<input id="bck-umax" name="upload_max_mb" type="number" min="1" max="50" inputmode="numeric"
						value="<?php p((int)($o['uploadMaxMb'] ?? 50)); ?>">
				</div>
				<div>
					<label for="bck-uquota" class="bck-label"><?php p($l->t('Total quota (MB)')); ?></label>
					<input id="bck-uquota" name="upload_quota_mb" type="number" min="1" max="102400" inputmode="numeric"
						value="<?php p((int)($o['uploadQuotaMb'] ?? 5120)); ?>">
				</div>
			</div>
			<label class="bck-checkbox">
				<input type="hidden" name="invoice_pay" value="0">
				<input type="checkbox" name="invoice_pay" value="1" <?php if ((int)($o['invoicePay'] ?? 0) === 1) { p('checked'); } ?>>
				<span><?php p($l->t('Buyers may also pay by invoice')); ?></span>
			</label>
			<div class="bck-field bck-field--row">
				<div>
					<label for="bck-deposit" class="bck-label"><?php p($l->t('Deposit (%%)')); ?></label>
					<input id="bck-deposit" name="deposit_pct" type="number" min="0" max="99" step="1" inputmode="numeric"
						value="<?php p((int)intdiv((int)($o['depositBp'] ?? 0), 100)); ?>">
					<p class="bck-field__hint"><?php p($l->t('Percent charged now; the rest is due later. 0 = full amount.')); ?></p>
				</div>
				<div>
					<label for="bck-dynbp" class="bck-label"><?php p($l->t('Fill markup (%%)')); ?></label>
					<input id="bck-dynbp" name="dyn_pct" type="number" min="0" max="100" step="1" inputmode="numeric"
						value="<?php p((int)intdiv((int)($o['dynBp'] ?? 0), 100)); ?>">
					<p class="bck-field__hint"><?php p($l->t('Price rises as seats fill (dynamic pricing). 0 = off.')); ?></p>
				</div>
			</div>
			<div class="bck-field" id="bck-dynstep-field">
				<label for="bck-dynstep" class="bck-label"><?php p($l->t('…applied per %% filled')); ?></label>
				<input id="bck-dynstep" name="dyn_step_pct" type="number" min="1" max="100" step="1" inputmode="numeric"
					value="<?php p((int)($o['dynStepPct'] ?? 25) === 0 ? 25 : (int)($o['dynStepPct'] ?? 25)); ?>">
				<p class="bck-field__hint"><?php p($l->t('Example: 25 means the markup grows in four steps.')); ?></p>
			</div>
			<div class="bck-field" id="bck-passscope-wrap">
				<label for="bck-passscope" class="bck-label"><?php p($l->t('Pass admits to offering ids')); ?>
					<span class="bck-label__opt">(<?php p($l->t('memberships only')); ?>)</span></label>
				<input id="bck-passscope" name="pass_scope" type="text" inputmode="numeric"
					value="<?php p(implode(' ', json_decode((string)($o['passScope'] ?? ''), true) ?: [])); ?>"
					placeholder="12 15">
				<p class="bck-field__hint"><?php p($l->t('Space-separated offering ids this pass grants admission to. Empty = the pass offering itself.')); ?></p>
				<p class="bck-field-error" id="bck-pass_scope-error" hidden></p>
			</div>
			<div class="bck-field">
				<label for="bck-embed" class="bck-label"><?php p($l->t('Embed allowed on these sites')); ?>
					<span class="bck-label__opt">(<?php p($l->t('optional')); ?>)</span></label>
				<textarea id="bck-embed" name="embed_domains" rows="2" maxlength="2000"
					placeholder="https://shop.example.com"><?php p(implode("\n", json_decode((string)($o['embedDomains'] ?? ''), true) ?: [])); ?></textarea>
				<p class="bck-field__hint"><?php p($l->t('One https:// origin per line. Empty = embedding disabled.')); ?></p>
				<p class="bck-field-error" id="bck-embed_domains-error" hidden></p>
			</div>
		</fieldset>
		<?php else: ?>
		<div class="bck-callout bck-callout--info" role="status">
			<p class="bck-callout__text">
				<?php p($l->t('Waitlists, reviews, file sharing and website embeds come with BookingCheck Pro.')); ?>
				<a href="<?php p($url->linkToRoute('bookingcheck.page.pro')); ?>"><?php p($l->t('See what Pro unlocks')); ?></a>
			</p>
		</div>
		<?php endif; ?>

		<div class="bck-form-actions">
			<button type="submit" class="bck-btn bck-btn--primary"><?php p($isNew ? $l->t('Create') : $l->t('Save')); ?></button>
			<a class="bck-btn bck-btn--secondary" href="<?php p($url->linkToRoute('bookingcheck.offering.index')); ?>"><?php p($l->t('Cancel')); ?></a>
		</div>
	</form>
</div>
<?php include __DIR__ . '/../common/page-end.php'; ?>

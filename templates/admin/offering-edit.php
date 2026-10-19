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
						<?php foreach (['event' => $l->t('Single event'), 'series' => $l->t('Course / series'), 'slots' => $l->t('Bookable timeslots'), 'evergreen' => $l->t('Open-ended offer')] as $v => $lbl): ?>
						<option value="<?php p($v); ?>" <?php if (($o['type'] ?? 'event') === $v) { p('selected'); } ?>><?php p($lbl); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="bck-field">
					<label for="bck-price" class="bck-label"><?php p($l->t('Price per seat')); ?></label>
					<input id="bck-price" name="price" type="number" min="0" step="0.01" inputmode="decimal"
						value="<?php p(number_format(((int)($o['price_cents'] ?? 0)) / 100, 2, '.', '')); ?>">
					<p class="bck-field__hint"><?php p($l->t('0.00 makes it a free booking.')); ?></p>
				</div>
				<div class="bck-field">
					<label for="bck-capacity" class="bck-label"><?php p($l->t('Seats')); ?></label>
					<input id="bck-capacity" name="capacity" type="number" min="0" step="1"
						value="<?php p((string)($o['capacity'] ?? 0)); ?>">
					<p class="bck-field__hint"><?php p($l->t('0 means unlimited.')); ?></p>
				</div>
			</div>
			<div class="bck-field">
				<label for="bck-descr" class="bck-label"><?php p($l->t('Description')); ?></label>
				<textarea id="bck-descr" name="descr" rows="4" maxlength="5000"><?php p((string)($o['descr'] ?? '')); ?></textarea>
			</div>
		</fieldset>

		<fieldset class="bck-fieldset">
			<legend class="bck-fieldset__legend"><?php p($l->t('Options')); ?></legend>
			<label class="bck-check">
				<input type="hidden" name="approval_required" value="0">
				<input type="checkbox" name="approval_required" value="1" <?php if ((int)($o['approval_required'] ?? 0) === 1) { p('checked'); } ?>>
				<span><?php p($l->t('Bookings need my approval first')); ?></span>
			</label>
			<label class="bck-check">
				<input type="hidden" name="list_in_directory" value="0">
				<input type="checkbox" name="list_in_directory" value="1" <?php if ((int)($o['list_in_directory'] ?? 1) === 1) { p('checked'); } ?>>
				<span><?php p($l->t('Show in the public directory feed')); ?></span>
			</label>
		</fieldset>

		<div class="bck-form-actions">
			<button type="submit" class="bck-btn bck-btn--primary"><?php p($isNew ? $l->t('Create') : $l->t('Save')); ?></button>
			<a class="bck-btn bck-btn--secondary" href="<?php p($url->linkToRoute('bookingcheck.offering.index')); ?>"><?php p($l->t('Cancel')); ?></a>
		</div>
	</form>
</div>
<?php include __DIR__ . '/../common/page-end.php'; ?>

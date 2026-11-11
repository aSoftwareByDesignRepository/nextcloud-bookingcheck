<?php
/** @var \OCP\IL10N $l */
$_['pageTitle'] = $l->t('Offering');
$_['pageIcon'] = 'calendar';
$_['pageActions'] = [[
	'label' => $l->t('Edit'), 'primary' => false, 'icon' => 'edit', 'id' => 'bck-edit-offering',
]];
\OCA\BookingCheck\Support\Chrome::apply($_, 'offerings');
include __DIR__ . '/../common/page-start.php';
$oid = (int)($_['offeringId'] ?? 0);
?>
<div class="bck-page-stack" data-bck-offering-detail="<?php p((string)$oid); ?>">
	<div class="bck-card" id="bck-offering-summary" aria-live="polite">
		<div class="bck-card__body" id="bck-offering-summary-body">
			<p class="bck-empty-state__text"><?php p($l->t('Loading…')); ?></p>
		</div>
		<div class="bck-card__footer" id="bck-offering-actions" hidden>
			<div class="bck-btn-row">
				<button type="button" class="bck-btn bck-btn--primary" id="bck-offer-publish"
					data-bck-offer-transition="<?php p((string)$oid); ?>" data-bck-to="published" hidden>
					<?php p($l->t('Publish')); ?></button>
				<button type="button" class="bck-btn bck-btn--secondary" id="bck-offer-unpublish"
					data-bck-offer-transition="<?php p((string)$oid); ?>" data-bck-to="draft"
					data-bck-confirm="unpublish" hidden>
					<?php p($l->t('Unpublish')); ?></button>
				<button type="button" class="bck-btn bck-btn--secondary" id="bck-offer-restore"
					data-bck-offer-transition="<?php p((string)$oid); ?>" data-bck-to="draft" hidden>
					<?php p($l->t('Restore to draft')); ?></button>
				<button type="button" class="bck-btn bck-btn--danger" id="bck-offer-archive"
					data-bck-offer-transition="<?php p((string)$oid); ?>" data-bck-to="archived"
					data-bck-confirm="archive" hidden>
					<?php p($l->t('Archive')); ?></button>
				<button type="button" class="bck-btn bck-btn--secondary" id="bck-offer-duplicate">
					<?php p($l->t('Duplicate')); ?></button>
			</div>
		</div>
	</div>

	<div class="bck-card" id="bck-share-card" hidden>
		<div class="bck-card__header">
			<div class="bck-card__header-text">
				<h2 class="bck-card__title"><?php p($l->t('Share this offering')); ?></h2>
				<p class="bck-card__lead"><?php p($l->t('Send the link or place the booking form on your website.')); ?></p>
			</div>
		</div>
		<div class="bck-card__body">
			<div class="bck-field">
				<label class="bck-label" for="bck-share-link"><?php p($l->t('Booking link')); ?></label>
				<div class="bck-field--row">
					<input class="bck-input" id="bck-share-link" readonly data-bck-select>
					<button type="button" class="bck-btn bck-btn--secondary" data-bck-copy="#bck-share-link"><?php p($l->t('Copy')); ?></button>
				</div>
			</div>
			<div id="bck-share-embed" class="bck-form" hidden>
				<div class="bck-field">
					<label class="bck-label" for="bck-share-iframe"><?php p($l->t('Embed — simple (iframe)')); ?></label>
					<textarea class="bck-input" id="bck-share-iframe" rows="3" readonly data-bck-select></textarea>
					<p class="bck-field__hint"><?php p($l->t('Paste this where your website allows iframes.')); ?></p>
					<button type="button" class="bck-btn bck-btn--secondary" data-bck-copy="#bck-share-iframe"><?php p($l->t('Copy')); ?></button>
				</div>
				<div class="bck-field">
					<label class="bck-label" for="bck-share-sdk"><?php p($l->t('Embed — seamless (recommended)')); ?></label>
					<textarea class="bck-input" id="bck-share-sdk" rows="3" readonly data-bck-select></textarea>
					<p class="bck-field__hint"><?php p($l->t('Paste this into your website HTML — the form blends into your page.')); ?></p>
					<button type="button" class="bck-btn bck-btn--secondary" data-bck-copy="#bck-share-sdk"><?php p($l->t('Copy')); ?></button>
				</div>
			</div>
			<p class="bck-field__hint" id="bck-share-note" hidden></p>
		</div>
	</div>

	<div class="bck-card">
		<div class="bck-card__header">
			<div class="bck-card__header-text">
				<h2 class="bck-card__title"><?php p($l->t('Dates and times')); ?></h2>
			</div>
		</div>
		<div class="bck-card__body">
			<div id="bck-occ-list">
				<p class="bck-empty-state__text"><?php p($l->t('Loading…')); ?></p>
			</div>
			<details class="bck-details">
				<summary class="bck-btn bck-btn--secondary"><?php p($l->t('Add a date')); ?></summary>
				<form id="bck-occ-form" class="bck-form bck-form--stack" data-offering-id="<?php p((string)$oid); ?>">
					<div class="bck-field">
						<label class="bck-label" for="bck-occ-start"><?php p($l->t('Starts')); ?></label>
						<input class="bck-input" id="bck-occ-start" name="starts_at" type="datetime-local" required>
					</div>
					<div class="bck-field">
						<label class="bck-label" for="bck-occ-end"><?php p($l->t('Ends')); ?></label>
						<input class="bck-input" id="bck-occ-end" name="ends_at" type="datetime-local" required>
					</div>
					<div class="bck-field">
						<label class="bck-label" for="bck-occ-cap"><?php p($l->t('Seats')); ?></label>
						<input class="bck-input" id="bck-occ-cap" name="capacity" type="number" min="1" required>
					</div>
					<div class="bck-field" id="bck-occ-tpl-field" hidden>
						<label class="bck-label" for="bck-occ-tpl"><?php p($l->t('Template')); ?></label>
						<select class="bck-select" id="bck-occ-tpl" name="tpl_id"></select>
					</div>
					<div class="bck-field">
						<label class="bck-checkbox">
							<input type="checkbox" id="bck-occ-series">
							<span><?php p($l->t('Repeat weekly')); ?></span>
						</label>
					</div>
					<div class="bck-field" id="bck-occ-weeks-field" hidden>
						<label class="bck-label" for="bck-occ-weeks"><?php p($l->t('Weeks')); ?></label>
						<input class="bck-input" id="bck-occ-weeks" name="weeks" type="number" min="2" max="52" value="4">
					</div>
					<p class="bck-field-error" role="alert" hidden></p>
					<button type="submit" class="bck-btn bck-btn--primary"><?php p($l->t('Add')); ?></button>
				</form>
			</details>
		</div>
	</div>

	<div class="bck-card" id="bck-tpl-card" hidden>
		<div class="bck-card__header">
			<div class="bck-card__header-text">
				<h2 class="bck-card__title"><?php p($l->t('Recurring schedule')); ?></h2>
				<p class="bck-card__lead"><?php p($l->t('Weekly patterns — the app keeps creating bookable slots for you.')); ?></p>
			</div>
		</div>
		<div class="bck-card__body">
			<div id="bck-tpl-list"></div>
			<details class="bck-details">
				<summary class="bck-btn bck-btn--secondary"><?php p($l->t('New recurring pattern')); ?></summary>
				<form id="bck-tpl-form" class="bck-form bck-form--stack">
					<div class="bck-field--row">
						<div class="bck-field">
							<label class="bck-label" for="bck-tpl-duration"><?php p($l->t('Length (minutes)')); ?></label>
							<input class="bck-input" id="bck-tpl-duration" name="duration_min" type="number" min="1" max="1440" inputmode="numeric" required>
						</div>
						<div class="bck-field">
							<label class="bck-label" for="bck-tpl-cap"><?php p($l->t('Seats per slot')); ?></label>
							<input class="bck-input" id="bck-tpl-cap" name="cap_per_slot" type="number" min="1" max="100000" inputmode="numeric" required>
						</div>
					</div>
					<div class="bck-field">
						<label class="bck-label" for="bck-tpl-rrule"><?php p($l->t('Weekly times')); ?></label>
						<textarea class="bck-input" id="bck-tpl-rrule" name="weekly_rrule" rows="3" required
							placeholder="MO 10:00&#10;WE 14:30&#10;FR 09:00,11:00"></textarea>
						<p class="bck-field__hint"><?php p($l->t('One weekday + start time(s) per line, e.g. "MO 10:00" or "FR 09:00,11:00".')); ?></p>
					</div>
					<div class="bck-field--row">
						<div class="bck-field">
							<label class="bck-label" for="bck-tpl-horizon"><?php p($l->t('Create ahead (days)')); ?></label>
							<input class="bck-input" id="bck-tpl-horizon" name="horizon_days" type="number" min="1" max="365" inputmode="numeric" value="60">
						</div>
						<div class="bck-field">
							<label class="bck-label" for="bck-tpl-lead"><?php p($l->t('Bookable until (minutes before)')); ?></label>
							<input class="bck-input" id="bck-tpl-lead" name="lead_min" type="number" min="0" inputmode="numeric" value="0">
						</div>
						<div class="bck-field">
							<label class="bck-label" for="bck-tpl-resource"><?php p($l->t('Resource pool')); ?></label>
							<select class="bck-select" id="bck-tpl-resource" name="resource_id">
								<option value=""><?php p($l->t('— none —')); ?></option>
							</select>
						</div>
					</div>
					<p class="bck-field-error" role="alert" hidden></p>
					<button type="submit" class="bck-btn bck-btn--secondary"><?php p($l->t('Create')); ?></button>
				</form>
			</details>
		</div>
	</div>

	<div class="bck-card">
		<div class="bck-card__header">
			<div class="bck-card__header-text">
				<h2 class="bck-card__title"><?php p($l->t('Price categories')); ?></h2>
				<p class="bck-card__lead"><?php p($l->t('e.g. Adult, Child, Member — buyers pick one per seat.')); ?></p>
			</div>
		</div>
		<div class="bck-card__body">
			<div id="bck-pricecat-list"></div>
			<form id="bck-pricecat-form" class="bck-form" data-offering-id="<?php p((string)$oid); ?>">
				<div class="bck-field">
					<label class="bck-label" for="bck-pc-name"><?php p($l->t('Name')); ?></label>
					<input class="bck-input" id="bck-pc-name" name="name" type="text" maxlength="190" required>
				</div>
				<div class="bck-field">
					<label class="bck-label" for="bck-pc-price"><?php p($l->t('Price (cents)')); ?></label>
					<input class="bck-input" id="bck-pc-price" name="price_cents" type="number" min="1" required>
				</div>
				<p class="bck-field-error" role="alert" hidden></p>
				<button type="submit" class="bck-btn bck-btn--secondary"><?php p($l->t('Add')); ?></button>
			</form>
		</div>
	</div>

	<div class="bck-card">
		<div class="bck-card__header">
			<div class="bck-card__header-text">
				<h2 class="bck-card__title"><?php p($l->t('Add-ons')); ?></h2>
				<p class="bck-card__lead"><?php p($l->t('Extras buyers can add at checkout, e.g. lunch, materials.')); ?></p>
			</div>
		</div>
		<div class="bck-card__body">
			<div id="bck-addon-list"></div>
			<form id="bck-addon-form" class="bck-form" data-offering-id="<?php p((string)$oid); ?>">
				<div class="bck-field">
					<label class="bck-label" for="bck-ad-name"><?php p($l->t('Name')); ?></label>
					<input class="bck-input" id="bck-ad-name" name="name" type="text" maxlength="190" required>
				</div>
				<div class="bck-field">
					<label class="bck-label" for="bck-ad-price"><?php p($l->t('Price (cents)')); ?></label>
					<input class="bck-input" id="bck-ad-price" name="price_cents" type="number" min="1" required>
				</div>
				<p class="bck-field-error" role="alert" hidden></p>
				<button type="submit" class="bck-btn bck-btn--secondary"><?php p($l->t('Add')); ?></button>
			</form>
		</div>
	</div>

	<div class="bck-card">
		<div class="bck-card__header">
			<div class="bck-card__header-text">
				<h2 class="bck-card__title"><?php p($l->t('Questions for attendees')); ?></h2>
				<p class="bck-card__lead"><?php p($l->t('Asked per ticket on the manage page after payment.')); ?></p>
			</div>
		</div>
		<div class="bck-card__body">
			<div id="bck-intake-list"></div>
			<form id="bck-intake-form" class="bck-form bck-form--stack" data-offering-id="<?php p((string)$oid); ?>">
				<div class="bck-field">
					<label class="bck-label" for="bck-iq-label"><?php p($l->t('Question')); ?></label>
					<input class="bck-input" id="bck-iq-label" name="label" type="text" maxlength="500" required>
				</div>
				<div class="bck-field">
					<label class="bck-label" for="bck-iq-kind"><?php p($l->t('Answer type')); ?></label>
					<select class="bck-select" id="bck-iq-kind" name="kind">
						<option value="text"><?php p($l->t('Text')); ?></option>
						<option value="select"><?php p($l->t('Choice list')); ?></option>
						<option value="checkbox"><?php p($l->t('Yes / no')); ?></option>
						<option value="date"><?php p($l->t('Date')); ?></option>
					</select>
				</div>
				<div class="bck-field" id="bck-iq-options-field" hidden>
					<label class="bck-label" for="bck-iq-options"><?php p($l->t('Choices — one per line')); ?></label>
					<textarea class="bck-input" id="bck-iq-options" name="options" rows="3"></textarea>
				</div>
				<div class="bck-field">
					<label class="bck-label" for="bck-iq-visible"><?php p($l->t('Answers visible to')); ?></label>
					<select class="bck-select" id="bck-iq-visible" name="visible_to">
						<option value="admin"><?php p($l->t('Back office only')); ?></option>
						<option value="roster"><?php p($l->t('Roster')); ?></option>
						<option value="door"><?php p($l->t('Door app')); ?></option>
					</select>
				</div>
				<div class="bck-field">
					<label class="bck-checkbox">
						<input type="checkbox" name="required" value="1">
						<span><?php p($l->t('Answer required')); ?></span>
					</label>
				</div>
				<p class="bck-field-error" role="alert" hidden></p>
				<button type="submit" class="bck-btn bck-btn--secondary"><?php p($l->t('Add question')); ?></button>
			</form>
		</div>
	</div>

	<div class="bck-card">
		<div class="bck-card__header">
			<div class="bck-card__header-text">
				<h2 class="bck-card__title"><?php p($l->t('Materials')); ?></h2>
				<p class="bck-card__lead"><?php p($l->t('Files buyers can download from the portal.')); ?></p>
			</div>
		</div>
		<div class="bck-card__body">
			<div id="bck-material-list"></div>
			<form id="bck-material-form" class="bck-form" data-offering-id="<?php p((string)$oid); ?>" enctype="multipart/form-data">
				<div class="bck-field">
					<label class="bck-label" for="bck-mat-title"><?php p($l->t('Title')); ?></label>
					<input class="bck-input" id="bck-mat-title" name="title" type="text" maxlength="190" required>
				</div>
				<div class="bck-field">
					<label class="bck-label" for="bck-mat-file"><?php p($l->t('File')); ?></label>
					<input class="bck-input" id="bck-mat-file" name="file" type="file" required>
				</div>
				<p class="bck-field-error" role="alert" hidden></p>
				<button type="submit" class="bck-btn bck-btn--secondary"><?php p($l->t('Upload')); ?></button>
			</form>
		</div>
	</div>

	<?php if (!empty($_['pro'])): ?>
	<div class="bck-card">
		<div class="bck-card__header">
			<div class="bck-card__header-text">
				<h2 class="bck-card__title"><?php p($l->t('Attendee files')); ?></h2>
				<p class="bck-card__lead"><?php p($l->t('Files buyers shared via their manage link.')); ?></p>
			</div>
		</div>
		<div class="bck-card__body">
			<div id="bck-upload-list">
				<p class="bck-empty-state__text"><?php p($l->t('Loading…')); ?></p>
			</div>
		</div>
	</div>

	<div class="bck-card">
		<div class="bck-card__header">
			<div class="bck-card__header-text">
				<h2 class="bck-card__title"><?php p($l->t('Message attendees')); ?></h2>
				<p class="bck-card__lead"><?php p($l->t('One email to every paid buyer of this offering.')); ?></p>
			</div>
		</div>
		<div class="bck-card__body">
			<div id="bck-broadcast-list"></div>
			<form id="bck-broadcast-form" class="bck-form bck-form--stack" data-offering-id="<?php p((string)$oid); ?>">
				<div class="bck-field">
					<label class="bck-label" for="bck-bc-subject"><?php p($l->t('Subject')); ?></label>
					<input class="bck-input" id="bck-bc-subject" name="subject" type="text" minlength="3" maxlength="190" required>
				</div>
				<div class="bck-field">
					<label class="bck-label" for="bck-bc-body"><?php p($l->t('Message')); ?></label>
					<textarea class="bck-input" id="bck-bc-body" name="body" rows="5" maxlength="8000" required></textarea>
				</div>
				<div class="bck-field" id="bck-bc-occ-field" hidden>
					<label class="bck-label" for="bck-bc-occ"><?php p($l->t('Only to attendees of')); ?></label>
					<select class="bck-select" id="bck-bc-occ" name="occurrence">
						<option value=""><?php p($l->t('Every paid buyer')); ?></option>
					</select>
				</div>
				<p class="bck-field-error" role="alert" hidden></p>
				<button type="submit" class="bck-btn bck-btn--primary"><?php p($l->t('Send email')); ?></button>
			</form>
		</div>
	</div>
	<?php endif; ?>
</div>
<?php include __DIR__ . '/../common/page-end.php'; ?>

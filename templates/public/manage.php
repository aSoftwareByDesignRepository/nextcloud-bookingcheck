<?php
/** Manage link — order at a glance + the legal actions for its state. */
\OCA\BookingCheck\Support\AssetRegistrar::registerPublic('public');
$order = $_['order'] ?? [];
$lines = $_['lines'] ?? [];
$actions = $_['actions'] ?? [];
$reviewEnabled = (bool)($_['reviewEnabled'] ?? false);
$reviewed = $_['reviewed'] ?? [];
$questions = $_['questions'] ?? [];
$savedAnswers = $_['answers'] ?? [];
$url = \OCP\Server::get(\OCP\IURLGenerator::class);
$token = (string)($_['token'] ?? '');
?>
<div class="bck-pub bck-pub--narrow">
	<div class="bck-card">
		<div class="bck-card__header">
			<div class="bck-card__header-text">
				<h1 class="bck-card__title"><?php p($l->t('Your booking') . ' ' . (string)$order['ref']); ?></h1>
				<p class="bck-card__lead"><?php p((string)$order['buyer_name']); ?></p>
			</div>
			<span class="bck-badge <?php p('bck-badge--' . (['paid' => 'success', 'pending_payment' => 'warning', 'pending_approval' => 'info', 'refunded' => 'danger', 'cancelled' => 'info', 'failed' => 'danger'][$order['status']] ?? 'info')); ?>">
				<?php p((string)$order['status']); ?></span>
		</div>
		<div class="bck-card__body">
			<p><strong><?php p((string)$order['currency'] . ' ' . number_format(((int)$order['amount_cents']) / 100, 2, ',', '.')); ?></strong></p>
			<ul class="bck-line-list">
				<?php foreach ($lines as $ln): ?>
					<li class="bck-line-list__item">
						<span><?php p((string)($ln['label'] ?? $l->t('Seat'))); ?></span>
						<?php foreach (($ticketsByLine[(int)$ln['id']] ?? []) as $t): ?>
							<a class="bck-btn bck-btn--small bck-btn--secondary"
								href="<?php p($url->linkToRoute('bookingcheck.manage.ticket', ['token' => $token, 'ticketId' => (int)$t['id']])); ?>">
								<?php p($l->t('Ticket') . ($t['seat_no'] !== null ? ' ' . $t['seat_no'] : '')); ?></a>
						<?php endforeach; ?>
						<?php if ($questions !== [] && $order['status'] === 'paid'): ?>
							<?php foreach (($ticketsByLine[(int)$ln['id']] ?? []) as $t): ?>
								<details class="bck-intake">
									<summary class="bck-intake__summary">
										<?php p($l->t('Questions for') . ' ' . (($t['attendee_name'] ?? '') !== '' ? $t['attendee_name'] : ($l->t('Seat') . ' ' . (string)($t['seat_no'] ?? '')))); ?>
									</summary>
									<form class="bck-form bck-intake__form" data-bck-intake-form
										data-ticket-id="<?php p((int)$t['id']); ?>"
										data-manage-token="<?php p($token); ?>">
										<?php foreach ($questions as $q): ?>
											<?php $sv = (string)($savedAnswers[(int)$t['id']][(int)$q['id']] ?? ''); ?>
											<div class="bck-field">
												<?php if ($q['kind'] === 'checkbox'): ?>
													<label class="bck-check">
														<input class="bck-check__input" type="checkbox"
															name="q<?php p((int)$q['id']); ?>" value="1"
															<?php if ($sv === '1'): ?>checked<?php endif; ?>>
														<span><?php p((string)$q['label']); ?><?php if ($q['required']): ?> <span class="bck-field__req" aria-hidden="true">*</span><?php endif; ?></span>
													</label>
												<?php else: ?>
													<label class="bck-label" for="bck-q<?php p((int)$t['id']); ?>-<?php p((int)$q['id']); ?>">
														<?php p((string)$q['label']); ?>
														<?php if ($q['required']): ?><span class="bck-field__req" aria-hidden="true">*</span><?php endif; ?>
													</label>
													<?php if ($q['kind'] === 'select'): ?>
														<select class="bck-select" id="bck-q<?php p((int)$t['id']); ?>-<?php p((int)$q['id']); ?>"
															name="q<?php p((int)$q['id']); ?>" <?php if ($q['required']): ?>required<?php endif; ?>>
															<option value=""><?php p($l->t('Choose…')); ?></option>
															<?php foreach ($q['options'] as $opt): ?>
																<option value="<?php p((string)$opt); ?>" <?php if ($sv === (string)$opt): ?>selected<?php endif; ?>><?php p((string)$opt); ?></option>
															<?php endforeach; ?>
														</select>
													<?php else: ?>
														<input class="bck-input" type="<?php p($q['kind'] === 'date' ? 'date' : 'text'); ?>"
															id="bck-q<?php p((int)$t['id']); ?>-<?php p((int)$q['id']); ?>"
															name="q<?php p((int)$q['id']); ?>" value="<?php p($sv); ?>"
															<?php if ($q['required']): ?>required<?php endif; ?>>
													<?php endif; ?>
												<?php endif; ?>
												<p class="bck-field-error" data-err="q<?php p((int)$q['id']); ?>" role="alert" hidden></p>
											</div>
										<?php endforeach; ?>
										<button class="bck-btn bck-btn--small bck-btn--primary" type="submit"><?php p($l->t('Save answers')); ?></button>
										<p class="bck-field-hint" data-intake-saved role="status" hidden><?php p($l->t('Saved.')); ?></p>
									</form>
								</details>
							<?php endforeach; ?>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<div class="bck-btn-row">
				<?php if (in_array('ics', $actions, true)): ?>
					<a class="bck-btn bck-btn--secondary"
						href="<?php p($url->linkToRoute('bookingcheck.manage.ics', ['token' => $token])); ?>">
						<?php p($l->t('Add to calendar')); ?></a>
				<?php endif; ?>
				<?php if (in_array('invoice', $actions, true)): ?>
					<a class="bck-btn bck-btn--secondary"
						href="<?php p($url->linkToRoute('bookingcheck.manage.invoice', ['token' => $token])); ?>">
						<?php p($l->t('Invoice')); ?></a>
					<a class="bck-btn bck-btn--flat"
						href="<?php p($url->linkToRoute('bookingcheck.manage.einvoice', ['token' => $token])); ?>">
						<?php p($l->t('E-invoice (XML)')); ?></a>
				<?php endif; ?>
				<?php if (in_array('cancel', $actions, true)): ?>
					<form method="post" action="<?php p($url->linkToRoute('bookingcheck.manage.cancel', ['token' => $token])); ?>">
						<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken'] ?? ''); ?>">
						<button type="submit" class="bck-btn bck-btn--danger"><?php p($l->t('Cancel booking')); ?></button>
					</form>
				<?php endif; ?>
				<?php if (in_array('pay', $actions, true)): ?>
					<form method="post" action="<?php p($url->linkToRoute('bookingcheck.manage.pay', ['token' => $token])); ?>">
						<button type="submit" class="bck-btn bck-btn--primary"><?php p($l->t('Pay now')); ?></button>
					</form>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<?php if (!empty($_['canUpload']) || !empty($_['uploads'])): ?>
	<div class="bck-card bck-mt-4">
		<div class="bck-card__body">
			<h2 class="bck-card__title"><?php p($l->t('Shared files')); ?></h2>
			<?php if (!empty($_['uploads'])): ?>
			<ul class="bck-upload-list">
				<?php foreach ($_['uploads'] as $up): ?>
				<li class="bck-upload-item" data-upload-id="<?php p((int)$up['id']); ?>">
					<?php if (!empty($up['thumbRef'])): ?>
					<img class="bck-upload-item__thumb" loading="lazy" decoding="async"
						src="<?php p($url->linkToRoute('bookingcheck.manage.uploadThumb', ['token' => $token, 'id' => (int)$up['id']])); ?>"
						alt="" width="48" height="48">
					<?php endif; ?>
					<a class="bck-upload-item__name"
						href="<?php p($url->linkToRoute('bookingcheck.manage.uploadDownload', ['token' => $token, 'id' => (int)$up['id']])); ?>">
						<?php p((string)$up['displayName']); ?></a>
					<span class="bck-upload-item__meta">
						<?php p(number_format((int)$up['sizeBytes'] / 1048576, 1) . ' MB'); ?>
					</span>
					<?php if (!empty($up['own'])): ?>
					<button type="button" class="bck-btn bck-btn--flat bck-upload-del"
						data-upload-id="<?php p((int)$up['id']); ?>"
						data-manage-token="<?php p($token); ?>"><?php p($l->t('Remove')); ?></button>
					<?php else: ?>
					<button type="button" class="bck-btn bck-btn--flat bck-upload-report"
						data-upload-id="<?php p((int)$up['id']); ?>"
						data-manage-token="<?php p($token); ?>"><?php p($l->t('Report')); ?></button>
					<?php endif; ?>
				</li>
				<?php endforeach; ?>
			</ul>
			<?php endif; ?>
			<?php if (!empty($_['canUpload'])): ?>
			<?php $seatLines = array_values(array_filter($lines, fn ($ln) => ($ln['lineKind'] ?? 'seat') === 'seat')); ?>
			<form class="bck-form bck-upload-form" data-manage-token="<?php p($token); ?>"
				enctype="multipart/form-data" novalidate>
				<div class="bck-field">
					<label class="bck-label" for="bck-up-name"><?php p($l->t('Shown as')); ?></label>
					<input class="bck-input" id="bck-up-name" name="display_name" type="text"
						maxlength="60" required placeholder="<?php p($l->t('e.g. Anna M.')); ?>">
					<p class="bck-field__hint"><?php p($l->t('This name is visible to others — not your email.')); ?></p>
				</div>
				<?php if (count($seatLines) > 1): ?>
				<div class="bck-field">
					<label class="bck-label" for="bck-up-line"><?php p($l->t('For')); ?></label>
					<select class="bck-select" id="bck-up-line" name="line_id">
						<?php foreach ($seatLines as $sl): ?>
						<option value="<?php p((int)$sl['id']); ?>"><?php p((string)($sl['catName'] ?? '#' . (int)$sl['id'])); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<?php else: ?>
				<input type="hidden" name="line_id" value="<?php p((int)($seatLines[0]['id'] ?? 0)); ?>">
				<?php endif; ?>
				<div class="bck-field">
					<label class="bck-label" for="bck-up-file"><?php p($l->t('File')); ?></label>
					<input class="bck-input" id="bck-up-file" name="file" type="file" required>
				</div>
				<p class="bck-field-error" role="alert" hidden></p>
				<button type="submit" class="bck-btn bck-btn--primary"><?php p($l->t('Share file')); ?></button>
			</form>
			<?php endif; ?>
		</div>
	</div>
	<?php endif; ?>
	<?php if ($reviewEnabled): ?>
		<?php foreach ($lines as $ln): if (in_array((int)$ln['id'], $reviewed, true)) { continue; } ?>
			<div class="bck-card bck-mt-4">
				<div class="bck-card__body">
					<form class="bck-review-form" data-line-id="<?php p((int)$ln['id']); ?>"
						data-manage-token="<?php p($token); ?>">
						<h2 class="bck-card__title"><?php p($l->t('How was') . ' ' . (string)($ln['label'] ?? $l->t('your booking')) . '?'); ?></h2>
						<div class="bck-field">
							<label class="bck-label" for="bck-review-rating-<?php p((int)$ln['id']); ?>"><?php p($l->t('Rating')); ?></label>
							<select class="bck-select" id="bck-review-rating-<?php p((int)$ln['id']); ?>" name="rating" required>
								<option value="5">★★★★★</option><option value="4">★★★★☆</option>
								<option value="3">★★★☆☆</option><option value="2">★★☆☆☆</option>
								<option value="1">★☆☆☆☆</option>
							</select>
						</div>
						<div class="bck-field">
							<label class="bck-label" for="bck-review-text-<?php p((int)$ln['id']); ?>"><?php p($l->t('Your review (optional)')); ?></label>
							<textarea class="bck-input" id="bck-review-text-<?php p((int)$ln['id']); ?>"
								name="text" rows="3" maxlength="2000"></textarea>
						</div>
						<p class="bck-field-error" role="alert" hidden></p>
						<button type="submit" class="bck-btn bck-btn--primary"><?php p($l->t('Send review')); ?></button>
					</form>
				</div>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>
</div>

<?php

declare(strict_types=1);

/**
 * Check family — canonical license page (canonical source in _shared/license-page).
 *
 * SYNCED FILE — never edit the in-app copy; edit the source and re-run
 * sync_license_page.py. bck is substituted with the app prefix at sync time.
 *
 * Renders the ONE license UX for every Check app with purchasable companions:
 * status tiles → organisation license key card → mobile seats (combobox +
 * batch assign + table) → optional device card (link or register mode).
 * Design reference: ArbeitszeitCheck admin-license page.
 *
 * Host supplies via $_ (see _shared/license-page/README.md for the contract):
 *   license, productCode, companionText, seatAppName,
 *   mobileSeatsUsed/Limit, mobileSeats[], showMobileSeats,
 *   terminalDevicesUsed/Limit, terminals[], showTerminal, terminalMode,
 *   deviceStrings, instanceId, instanceBound,
 *   licenseRenewMailto, productsUrl, deviceManageUrl, deviceManageLabel,
 *   api*Url endpoints, deviceFields, requesttoken, i18n (overrides)
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @license AGPL-3.0-or-later
 */

$l = $_['l'] ?? ($l ?? null);
if (!is_object($l) || !method_exists($l, 't')) {
	try {
		$l = \OCP\Util::getL10N((string)($_['appId'] ?? ''));
	} catch (\Throwable) {
		// Kernel-free template render (unit tests): identity l10n stub.
		$l = new class {
			/** @param array<int|string, mixed> $parameters */
			public function t(string $text, array $parameters = []): string
			{
				return $parameters === [] ? $text : vsprintf($text, $parameters);
			}
		};
	}
}

$license = is_array($_['license'] ?? null) ? $_['license'] : null;
$productCode = (string)($_['productCode'] ?? '');
$companionText = (string)($_['companionText'] ?? $l->t('the companion apps'));
$seatAppName = (string)($_['seatAppName'] ?? $l->t('the mobile companion app'));

$mobileUsed = (int)($_['mobileSeatsUsed'] ?? 0);
$mobileLimit = (int)($_['mobileSeatsLimit'] ?? 0);
$mobileSeats = is_array($_['mobileSeats'] ?? null) ? $_['mobileSeats'] : [];
$showMobile = !empty($_['showMobileSeats']);

$terminalUsed = (int)($_['terminalDevicesUsed'] ?? 0);
$terminalLimit = (int)($_['terminalDevicesLimit'] ?? 0);
$terminals = is_array($_['terminals'] ?? null) ? $_['terminals'] : [];
$showTerminal = !empty($_['showTerminal']);
$terminalMode = (string)($_['terminalMode'] ?? 'link'); // 'link' | 'register'
$deviceFields = is_array($_['deviceFields'] ?? null) ? $_['deviceFields'] : [];
$deviceStrings = is_array($_['deviceStrings'] ?? null) ? $_['deviceStrings'] : [];

$instanceId = (string)($_['instanceId'] ?? '');
$instanceBound = !isset($_['instanceBound']) || !empty($_['instanceBound']);
$licenseRenewMailto = (string)($_['licenseRenewMailto'] ?? 'mailto:info@software-by-design.de');
$productsUrl = (string)($_['productsUrl'] ?? 'https://nextcloud.software-by-design.de/');
$deviceManageUrl = (string)($_['deviceManageUrl'] ?? '');
$deviceManageLabel = (string)($_['deviceManageLabel'] ?? $l->t('Manage devices'));

$apiLicenseUrl = (string)($_['apiLicenseUrl'] ?? '');
$apiClearLicenseUrl = (string)($_['apiClearLicenseUrl'] ?? '');
$apiSeatsUrl = (string)($_['apiSeatsUrl'] ?? '');
$apiSeatsBatchUrl = (string)($_['apiSeatsBatchUrl'] ?? '');
$apiRemoveSeatUrl = (string)($_['apiRemoveSeatUrl'] ?? '');
$apiSearchUsersUrl = (string)($_['apiSearchUsersUrl'] ?? '');
$apiRegisterDeviceUrl = (string)($_['apiRegisterDeviceUrl'] ?? '');
$apiRevokeDeviceUrl = (string)($_['apiRevokeDeviceUrl'] ?? '');
$requesttoken = (string)($_['requesttoken'] ?? '');

$ds = static function (string $key, string $fallback) use ($deviceStrings): string {
	$v = $deviceStrings[$key] ?? null;
	return is_string($v) && $v !== '' ? $v : $fallback;
};
$devicesTitle = $ds('title', $l->t('Devices'));
$devicesLead = $ds('lead', $l->t('Pair devices from the linked administration area. Each paired device uses one license slot.'));
$devicesLabel = $ds('label', $l->t('Devices'));
$deviceRegisterLabel = $ds('registerLabel', $l->t('Register device'));
$deviceNameLabel = $ds('nameLabel', $l->t('Display name'));
$deviceNamePlaceholder = $ds('namePlaceholder', '');
$deviceEmptyTitle = $ds('emptyTitle', $l->t('No devices registered yet.'));
$deviceEmptyText = $ds('emptyText', $l->t('Register a device above to give it access.'));
$deviceColLabel = $ds('colLabel', $l->t('Label'));
$deviceColDetail = $ds('colDetail', $l->t('Detail'));
$deviceColRegistered = $ds('colRegistered', $l->t('Registered'));
$deviceRevokeLabel = $ds('revokeLabel', $l->t('Revoke'));

$hasLicense = $license !== null;
$dateValid = $hasLicense && !empty($license['dateValid']);
$cryptoValid = $hasLicense && !empty($license['cryptographicallyValid']);
$isActive = $hasLicense && !empty($license['active']);
$cryptoInvalid = $dateValid && !$cryptoValid;
$validUntil = $hasLicense ? (string)($license['validUntil'] ?? '') : '';
$customerId = $hasLicense ? (string)($license['customerId'] ?? '') : '';
$expiresSoon = false;
if ($validUntil !== '') {
	$untilDt = \DateTimeImmutable::createFromFormat('Y-m-d', $validUntil);
	$today = new \DateTimeImmutable('today');
	if ($untilDt !== false) {
		$daysLeft = (int)$today->diff($untilDt)->format('%r%a');
		$expiresSoon = $daysLeft >= 0 && $daysLeft <= 30;
	}
}

$formatAssignedAt = static function (string $iso) use ($l): string {
	if ($iso === '') {
		return '—';
	}
	try {
		$dt = new \DateTimeImmutable($iso);
		return $dt->format('d.m.Y H:i');
	} catch (\Exception) {
		return $iso;
	}
};

$mobilePct = $mobileLimit > 0 ? min(100, (int)round(($mobileUsed / $mobileLimit) * 100)) : 0;
$terminalPct = $terminalLimit > 0 ? min(100, (int)round(($terminalUsed / $terminalLimit) * 100)) : 0;
$mobileFull = $mobileLimit > 0 && $mobileUsed >= $mobileLimit;
$terminalFull = $terminalLimit > 0 && $terminalUsed >= $terminalLimit;

/** @return list<array{href: string, label: string, class: string, target?: string, rel?: string}> */
$licenseContactActions = static function ($l) use ($licenseRenewMailto, $productsUrl): array {
	return [
		[
			'href' => $licenseRenewMailto,
			'label' => $l->t('Purchase or renew license'),
			'class' => 'bck-license-btn bck-license-btn--secondary bck-license-btn--sm',
		],
		[
			'href' => $productsUrl,
			'label' => $l->t('Software by Design — Nextcloud Apps'),
			'class' => 'bck-license-btn bck-license-btn--secondary bck-license-btn--sm',
			'target' => '_blank',
			'rel' => 'noopener noreferrer',
		],
	];
};

$renderContactActions = static function ($l) use ($licenseContactActions): void {
	foreach ($licenseContactActions($l) as $action) {
		$target = !empty($action['target']) ? ' target="' . \htmlspecialchars((string)$action['target'], ENT_QUOTES, 'UTF-8') . '"' : '';
		$rel = !empty($action['rel']) ? ' rel="' . \htmlspecialchars((string)$action['rel'], ENT_QUOTES, 'UTF-8') . '"' : '';
		echo '<a href="' . \htmlspecialchars($action['href'], ENT_QUOTES, 'UTF-8') . '" class="' . \htmlspecialchars($action['class'], ENT_QUOTES, 'UTF-8') . '"' . $target . $rel . '>' . \htmlspecialchars($action['label'], ENT_QUOTES, 'UTF-8') . '</a>';
	}
};

$trashIcon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="bck-license-icon" aria-hidden="true" focusable="false"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>';

$i18nMsgids = [
	'saveSuccess' => 'License saved successfully.',
	'saveFailed' => 'Could not save license.',
	'emptyKey' => 'Please paste a license key.',
	'networkError' => 'Network error. Please try again.',
	'seatAssigned' => 'Seat assigned.',
	'seatsAssigned' => 'Assigned %1$d, skipped %2$d, failed %3$d.',
	'assignSelected' => 'Assign selected',
	'assignSelectedN' => 'Assign selected (%n)',
	'confirmAssignSeats' => 'Assign mobile seats to %1$d people? %2$d seats remaining.',
	'assignSeatsTitle' => 'Assign mobile seats?',
	'assignSeatsConfirm' => 'Assign seats',
	'confirmAssignSeatsOver' => 'You selected %1$d people but only %2$d seats remain. Only free seats will be assigned.',
	'selectPeopleFirst' => 'Select at least one person.',
	'searching' => 'Searching…',
	'searchFailed' => 'Search failed. Please try again.',
	'seatRemoved' => 'Seat removed.',
	'assignFailed' => 'Could not assign seat.',
	'removeFailed' => 'Could not remove seat.',
	'removeSeat' => 'Remove',
	'removeSeatTitle' => 'Remove mobile seat',
	'removeSeatConfirm' => 'Remove mobile seat for this person?',
	'clearSuccess' => 'License removed.',
	'clearFailed' => 'Could not remove license.',
	'cancel' => 'Cancel',
	'confirm' => 'Confirm',
	'activeLabel' => 'Active',
	'inactiveLabel' => 'Expired or invalid',
	'signatureInvalidLabel' => 'Signature mismatch',
	'searchNoResults' => 'No matching people found.',
	'saving' => 'Saving…',
	'colPerson' => 'Person',
	'colUserId' => 'User ID',
	'colAssigned' => 'Assigned',
	'colActions' => 'Actions',
	'seatCountFmt' => '%1$d of %2$d seats assigned',
	'deviceRegistered' => 'Device registered.',
	'deviceRegisterFailed' => 'Could not register device.',
	'deviceRevoked' => 'Device revoked.',
	'deviceRevokeFailed' => 'Could not revoke device.',
	'deviceRevokeTitle' => 'Revoke device',
	'deviceRevokeConfirm' => 'Revoke this device? The device loses access immediately.',
	'deviceCountFmt' => '%1$d of %2$d device slots in use.',
	'deviceTokenHint' => 'This token is shown only once. Paste it into the companion app on the device.',
	'copyToken' => 'Copy token',
	'copied' => 'Copied.',
	'done' => 'Done',
	'requiredField' => 'Please fill in all required fields.',
	'regenTitle' => 'New pairing code',
	'regenConfirm' => 'Generate a new pairing code for this device? The old code stops working immediately.',
	'regenOk' => 'Generate code',
	'regenDone' => 'New pairing code generated.',
	'regenFailed' => 'Could not generate a pairing code.',
];
$i18n = [];
foreach ($i18nMsgids as $k => $msgid) {
	$i18n[$k] = $l->t($msgid);
	$i18n[$msgid] = $i18n[$k];
}
if (is_array($_['i18n'] ?? null)) {
	foreach ($_['i18n'] as $k => $v) {
		$i18n[$k] = $v;
	}
}
$i18nJson = json_encode($i18n, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
if (!is_string($i18nJson)) {
	$i18nJson = '{}';
}
?>

<div class="bck-license-page" id="bck-license-page"
	data-license-root="1"
	data-prefix="bck"
	data-api-license="<?php p($apiLicenseUrl); ?>"
	data-api-clear-license="<?php p($apiClearLicenseUrl); ?>"
	data-api-seats="<?php p($apiSeatsUrl); ?>"
	data-api-seats-batch="<?php p($apiSeatsBatchUrl); ?>"
	data-api-remove-seat="<?php p($apiRemoveSeatUrl); ?>"
	data-api-search-users="<?php p($apiSearchUsersUrl); ?>"
	data-api-register-device="<?php p($apiRegisterDeviceUrl); ?>"
	data-api-revoke-device="<?php p($apiRevokeDeviceUrl); ?>"
	data-api-regen-device="<?php p((string)($_['apiRegenDeviceUrl'] ?? '')); ?>"
	data-i18n="<?php p($i18nJson); ?>"
	data-requesttoken="<?php p($requesttoken); ?>">

	<span id="bck-license-heading" class="bck-license-anchor" aria-hidden="true"></span>
	<div id="bck-license-live" class="bck-license-sr-only" role="status" aria-live="polite" aria-atomic="true"></div>
	<div id="bck-license-alert" class="bck-license-sr-only" role="alert" aria-live="assertive" aria-atomic="true"></div>

	<div id="bck-license-feedback" class="bck-license-feedback" role="alert" hidden></div>

	<?php if ($cryptoInvalid): ?>
	<aside class="bck-license-callout bck-license-callout--warning bck-license-crypto-callout" role="alert" aria-labelledby="bck-license-crypto-title">
		<div class="bck-license-callout__body">
			<h2 id="bck-license-crypto-title" class="bck-license-callout__title"><?php p($l->t('License signature cannot be verified')); ?></h2>
			<p class="bck-license-callout__text"><?php p($l->t('A license key is stored, but this server cannot verify its signature with the vendor public key. The companion apps will reject the license until the key was signed with the matching vendor signing key. Re-apply a correctly signed license, or contact support if this persists after an app update.')); ?></p>
		</div>
	</aside>
	<?php endif; ?>

	<?php if (!$hasLicense): ?>
	<aside class="bck-license-callout bck-license-callout--info bck-license-no-key-callout" role="region" aria-labelledby="bck-license-no-key-title">
		<div class="bck-license-callout__body">
			<h2 id="bck-license-no-key-title" class="bck-license-callout__title"><?php p($l->t('No license yet')); ?></h2>
			<p class="bck-license-callout__text"><?php p($l->t('Paste your %1$s license key below to unlock %2$s. The web app stays free.', [$productCode, $companionText])); ?></p>
			<div class="bck-license-callout__actions">
				<?php $renderContactActions($l); ?>
			</div>
		</div>
	</aside>
	<?php endif; ?>

	<section class="bck-license-overview" aria-label="<?php p($l->t('License overview')); ?>">
		<div class="bck-license-stat-grid">
			<article class="bck-license-stat-tile bck-license-stat <?php echo $isActive ? 'bck-license-stat-tile--success' : ($hasLicense ? 'bck-license-stat-tile--warning' : 'bck-license-stat-tile--neutral'); ?>" aria-labelledby="bck-license-stat-status-label">
				<p id="bck-license-stat-status-label" class="bck-license-stat-tile__label"><?php p($l->t('Status')); ?></p>
				<p class="bck-license-stat-tile__value">
					<span id="bck-license-active-badge" class="bck-license-badge <?php echo $isActive ? 'bck-license-badge--success' : 'bck-license-badge--warning'; ?>"
						data-active-label="<?php p($l->t('Active')); ?>"
						data-inactive-label="<?php p($l->t('Expired or invalid')); ?>"
						data-signature-invalid-label="<?php p($l->t('Signature mismatch')); ?>">
						<?php
						if ($isActive) {
							p($l->t('Active'));
						} elseif ($cryptoInvalid) {
							p($l->t('Signature mismatch'));
						} elseif ($hasLicense) {
							p($l->t('Expired or invalid'));
						} else {
							p($l->t('Not configured'));
						}
						?>
					</span>
				</p>
				<?php if ($hasLicense): ?>
				<p class="bck-license-stat-tile__meta">
					<span class="bck-license-stat__meta-label"><?php p($l->t('Valid until')); ?>:</span>
					<span id="bck-license-valid-until"><?php p($validUntil !== '' ? $validUntil : '—'); ?></span>
				</p>
				<?php endif; ?>
			</article>

			<?php if ($showMobile || !$hasLicense): ?>
			<article class="bck-license-stat-tile bck-license-stat-tile--primary bck-license-stat" aria-labelledby="bck-license-stat-mobile-label">
				<p id="bck-license-stat-mobile-label" class="bck-license-stat-tile__label"><?php p($l->t('Mobile seats')); ?></p>
				<p class="bck-license-stat-tile__value">
					<span id="bck-license-mobile-used"><?php p((string)$mobileUsed); ?></span>
					<span class="bck-license-stat-tile__sep" aria-hidden="true">/</span>
					<span id="bck-license-mobile-limit"><?php p((string)$mobileLimit); ?></span>
				</p>
				<div class="bck-license-meter" role="meter"
					aria-valuemin="0"
					aria-valuemax="<?php p((string)max(1, $mobileLimit)); ?>"
					aria-valuenow="<?php p((string)$mobileUsed); ?>"
					aria-label="<?php p($l->t('Mobile seats used')); ?>">
					<div class="bck-license-meter__fill <?php echo $mobileFull ? 'bck-license-meter__fill--full' : ''; ?>"
						id="bck-license-mobile-meter"
						style="width: <?php p((string)$mobilePct); ?>%;"></div>
				</div>
			</article>
			<?php endif; ?>

			<?php if ($showTerminal || !$hasLicense): ?>
			<article class="bck-license-stat-tile bck-license-stat-tile--primary bck-license-stat" aria-labelledby="bck-license-stat-terminal-label">
				<p id="bck-license-stat-terminal-label" class="bck-license-stat-tile__label"><?php p($devicesLabel); ?></p>
				<p class="bck-license-stat-tile__value">
					<span id="bck-license-terminal-used"><?php p((string)$terminalUsed); ?></span>
					<span class="bck-license-stat-tile__sep" aria-hidden="true">/</span>
					<span id="bck-license-terminal-limit"><?php p((string)$terminalLimit); ?></span>
				</p>
				<div class="bck-license-meter" role="meter"
					aria-valuemin="0"
					aria-valuemax="<?php p((string)max(1, $terminalLimit)); ?>"
					aria-valuenow="<?php p((string)$terminalUsed); ?>"
					aria-label="<?php p($l->t('Devices in use')); ?>">
					<div class="bck-license-meter__fill <?php echo $terminalFull ? 'bck-license-meter__fill--full' : ''; ?>"
						id="bck-license-terminal-meter"
						style="width: <?php p((string)$terminalPct); ?>%;"></div>
				</div>
			</article>
			<?php endif; ?>
		</div>
	</section>

	<section class="bck-license-card bck-license-section" aria-labelledby="bck-license-key-heading">
		<header class="bck-license-card__header">
			<div class="bck-license-card__header-text">
				<h2 id="bck-license-key-heading" class="bck-license-card__title"><?php p($l->t('Organisation license')); ?></h2>
				<p class="bck-license-card__lead"><?php p($l->t('Paste the license key you received after purchase. The web app stays free — this key unlocks %1$s for your organisation.', [$companionText])); ?></p>
			</div>
		</header>
		<div class="bck-license-card__body">
		<div class="bck-license-form">
			<label for="bck-license-key-input" class="bck-license-field__label"><?php p($l->t('License key')); ?></label>
			<textarea id="bck-license-key-input"
				class="bck-license-key-input bck-license-input"
				name="licenseKey"
				rows="4"
				spellcheck="false"
				autocomplete="off"
				aria-describedby="bck-license-key-hint"
				placeholder="<?php p($productCode . '.…'); ?>"></textarea>
			<p id="bck-license-key-hint" class="bck-license-field__hint"><?php p($l->t('Format: %1$s followed by a signed payload. One key per organisation.', [$productCode])); ?></p>
			<?php if ($instanceId !== ''): ?>
			<div class="bck-license-instance-box" role="note" aria-labelledby="bck-license-instance-label">
				<p id="bck-license-instance-label" class="bck-license-field__label"><?php p($l->t('Nextcloud instance ID')); ?></p>
				<p class="bck-license-field__hint"><?php p($instanceBound
					? $l->t('Provide this ID when ordering a license so the key can be bound to this server.')
					: $l->t('This server’s Nextcloud instance ID (for support / sales reference). Licenses are not instance-bound.')); ?></p>
				<code class="bck-license-instance-id" id="bck-license-instance-id"><?php p($instanceId); ?></code>
			</div>
			<?php endif; ?>
			<div class="bck-license-form__actions">
				<button type="button" id="bck-license-save" class="bck-license-btn bck-license-btn--primary">
					<?php p($l->t('Save license')); ?>
				</button>
				<?php if ($hasLicense): ?>
				<button type="button" id="bck-license-clear" class="bck-license-btn bck-license-btn--danger">
					<?php p($l->t('Remove license')); ?>
				</button>
				<?php endif; ?>
			</div>
		</div>

		<div id="bck-license-status" class="bck-license-status" <?php echo $hasLicense ? '' : 'hidden'; ?>>
			<h3 class="bck-license-status__title"><?php p($l->t('Current license')); ?></h3>
			<dl class="bck-license-status__grid">
				<div class="bck-license-status__item">
					<dt><?php p($l->t('Customer ID')); ?></dt>
					<dd id="bck-license-customer"><?php p($customerId); ?></dd>
				</div>
			</dl>
			<?php if ($expiresSoon && $isActive): ?>
			<aside class="bck-license-callout bck-license-callout--warning bck-license-expiry-callout" role="note">
				<div class="bck-license-callout__body">
					<p class="bck-license-callout__title"><?php p($l->t('License expires soon')); ?></p>
					<p class="bck-license-callout__text"><?php p($l->t('Your license expires within 30 days. Contact your vendor to renew.')); ?></p>
					<div class="bck-license-callout__actions">
						<?php $renderContactActions($l); ?>
					</div>
				</div>
			</aside>
			<?php else: ?>
			<div class="bck-license-status__purchase">
				<?php $renderContactActions($l); ?>
			</div>
			<?php endif; ?>
		</div>
		</div><!-- /.bck-license-card__body -->
	</section>

	<?php if ($showMobile): ?>
	<section class="bck-license-card bck-license-section" id="bck-mobile-seats-section" aria-labelledby="bck-mobile-seats-heading">
		<header class="bck-license-card__header">
			<div class="bck-license-card__header-text">
				<span id="bck-license-seats-heading" class="bck-license-anchor" aria-hidden="true"></span>
				<h2 id="bck-mobile-seats-heading" class="bck-license-card__title"><?php p($l->t('Mobile seats')); ?></h2>
				<p class="bck-license-card__lead"><?php p($l->t('Choose which people may use %1$s. Only assigned users can sign in from their companion device.', [$seatAppName])); ?></p>
			</div>
		</header>
		<div class="bck-license-card__body">
		<div class="bck-license-seat-picker">
			<label for="bck-seat-user-search" class="bck-license-field__label"><?php p($l->t('Add person')); ?></label>
			<div class="bck-license-search-wrap">
				<input type="search"
					id="bck-seat-user-search"
					class="bck-license-input bck-license-search-input"
					autocomplete="off"
					placeholder="<?php p($l->t('Search by name or login…')); ?>"
					aria-describedby="bck-seat-picker-hint bck-seat-count"
					aria-controls="bck-seat-search-results"
					aria-expanded="false"
					aria-autocomplete="list"
					role="combobox"
					<?php echo $mobileFull ? 'disabled aria-disabled="true"' : ''; ?>>
				<ul id="bck-seat-search-results" class="bck-seat-search-results" role="listbox" hidden></ul>
			</div>
			<p id="bck-seat-picker-hint" class="bck-license-field__hint"><?php p($l->t('Type at least two characters, tick several people, then assign them together.')); ?></p>
			<p id="bck-seat-count" class="bck-license-seat-count" aria-live="polite">
				<?php p($l->t('%1$d of %2$d seats assigned', [$mobileUsed, $mobileLimit])); ?>
			</p>
			<button type="button" id="bck-seat-assign-selected" class="bck-license-btn bck-license-btn--primary bck-license-assign-btn" hidden disabled
				aria-live="polite">
				<?php p($l->t('Assign selected')); ?>
			</button>
			<p id="bck-seats-full-hint" class="bck-license-field__hint bck-license-seats-full-hint" role="status" <?php echo $mobileFull ? '' : 'hidden'; ?>>
				<?php p($l->t('All mobile seats are assigned. Remove a user or upgrade your license.')); ?>
			</p>
			<div id="bck-seat-batch-result" class="bck-license-callout bck-license-callout--info bck-license-batch-result" role="status" aria-live="polite" hidden></div>
		</div>

		<div class="bck-license-seats-table-wrap">
			<table class="bck-license-seats-table" aria-labelledby="bck-mobile-seats-heading">
				<thead>
					<tr>
						<th scope="col"><?php p($l->t('Person')); ?></th>
						<th scope="col"><?php p($l->t('User ID')); ?></th>
						<th scope="col"><?php p($l->t('Assigned')); ?></th>
						<th scope="col"><span class="bck-license-sr-only"><?php p($l->t('Actions')); ?></span></th>
					</tr>
				</thead>
				<tbody id="bck-seat-list-body">
					<?php foreach ($mobileSeats as $seat): ?>
						<tr data-user-id="<?php p((string)($seat['userId'] ?? $seat['uid'] ?? $seat['id'] ?? '')); ?>">
							<td data-label="<?php p($l->t('Person')); ?>"><?php p((string)($seat['displayName'] ?? '')); ?></td>
							<td data-label="<?php p($l->t('User ID')); ?>"><code class="bck-license-user-id"><?php p((string)($seat['userId'] ?? $seat['uid'] ?? $seat['id'] ?? '')); ?></code></td>
							<td data-label="<?php p($l->t('Assigned')); ?>"><?php p($formatAssignedAt((string)($seat['assignedAt'] ?? ''))); ?></td>
							<td data-label="<?php p($l->t('Actions')); ?>" class="actions-cell">
								<div class="bck-table-actions bck-license-table-actions" role="group" aria-label="<?php p($l->t('Actions')); ?>">
									<?php $seatRemoveLabel = $l->t('Remove') . ': ' . (string)($seat['displayName'] ?? $seat['userId'] ?? $seat['uid'] ?? ''); ?>
									<button type="button" class="bck-license-btn bck-license-btn--danger bck-license-btn--icon bck-seat-remove" data-user-id="<?php p((string)($seat['userId'] ?? $seat['uid'] ?? $seat['id'] ?? '')); ?>" aria-label="<?php p($seatRemoveLabel); ?>" title="<?php p($l->t('Remove')); ?>">
										<?php print_unescaped($trashIcon); ?>
									</button>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<div id="bck-seat-empty" class="bck-license-empty-state" <?php echo count($mobileSeats) > 0 ? 'hidden' : ''; ?>>
				<p class="bck-license-empty-state__title"><?php p($l->t('No mobile seats assigned yet.')); ?></p>
				<p class="bck-license-empty-state__text"><?php p($l->t('Search for a person above to grant companion app access.')); ?></p>
			</div>
		</div>
		</div><!-- /.bck-license-card__body -->
	</section>
	<?php endif; ?>

	<?php if ($showTerminal): ?>
	<section class="bck-license-card bck-license-section" aria-labelledby="bck-terminal-heading">
		<header class="bck-license-card__header">
			<div class="bck-license-card__header-text">
				<h2 id="bck-terminal-heading" class="bck-license-card__title"><?php p($devicesTitle); ?></h2>
				<p class="bck-license-card__lead"><?php p($devicesLead); ?></p>
			</div>
			<?php if ($terminalMode === 'link' && $deviceManageUrl !== ''): ?>
			<div class="bck-license-card__header-actions">
				<a href="<?php p($deviceManageUrl); ?>" class="bck-license-btn bck-license-btn--primary">
					<?php p($deviceManageLabel); ?>
				</a>
			</div>
			<?php endif; ?>
		</header>
		<div class="bck-license-card__body">
			<?php if ($terminalMode === 'link'): ?>
			<p class="bck-license-terminal-summary">
				<?php p($l->t('%1$d of %2$d device slots in use.', [$terminalUsed, $terminalLimit])); ?>
			</p>
			<?php else: ?>
			<div class="bck-license-terminal-form">
				<div class="bck-license-terminal-fields">
					<div class="bck-license-terminal-field">
						<label for="bck-terminal-label" class="bck-license-field__label"><?php p($deviceNameLabel); ?></label>
						<input type="text"
							id="bck-terminal-label"
							class="bck-license-input"
							maxlength="128"
							autocomplete="off"
							placeholder="<?php p($deviceNamePlaceholder); ?>"
							aria-describedby="bck-terminal-count bck-terminals-full-hint"
							<?php echo $terminalFull ? 'disabled aria-disabled="true"' : ''; ?>>
					</div>
					<?php foreach ($deviceFields as $field): ?>
					<?php
						$fName = (string)($field['name'] ?? '');
						if ($fName === '') {
							continue;
						}
						$fType = (string)($field['type'] ?? 'text');
						$fLabel = (string)($field['label'] ?? $fName);
						$fHint = (string)($field['hint'] ?? '');
						$fPlaceholder = (string)($field['placeholder'] ?? '');
						$fRequired = !empty($field['required']);
						$fId = 'bck-terminal-field-' . preg_replace('/[^a-z0-9_-]/i', '-', $fName);
						$fOptions = is_array($field['options'] ?? null) ? $field['options'] : [];
					?>
					<div class="bck-license-terminal-field">
						<label for="<?php p($fId); ?>" class="bck-license-field__label"><?php p($fLabel); ?><?php if ($fRequired) { ?> <span aria-hidden="true">*</span><?php } ?></label>
						<?php if ($fType === 'select' && $fOptions !== []): ?>
						<select id="<?php p($fId); ?>"
							class="bck-license-input"
							data-device-field="<?php p($fName); ?>"
							<?php echo $fRequired ? 'required aria-required="true"' : ''; ?>
							<?php echo $fHint !== '' ? 'aria-describedby="' . \htmlspecialchars($fId . '-hint', ENT_QUOTES, 'UTF-8') . '"' : ''; ?>
							<?php echo $terminalFull ? 'disabled aria-disabled="true"' : ''; ?>>
							<?php foreach ($fOptions as $opt): ?>
							<option value="<?php p((string)($opt['value'] ?? '')); ?>"><?php p((string)($opt['label'] ?? ($opt['value'] ?? ''))); ?></option>
							<?php endforeach; ?>
						</select>
						<?php else: ?>
						<input type="<?php p($fType === 'number' ? 'number' : 'text'); ?>"
							id="<?php p($fId); ?>"
							class="bck-license-input"
							data-device-field="<?php p($fName); ?>"
							<?php echo $fType === 'number' ? 'inputmode="numeric"' : ''; ?>
							maxlength="256"
							autocomplete="off"
							placeholder="<?php p($fPlaceholder); ?>"
							<?php echo $fRequired ? 'required aria-required="true"' : ''; ?>
							<?php echo $fHint !== '' ? 'aria-describedby="' . \htmlspecialchars($fId . '-hint', ENT_QUOTES, 'UTF-8') . '"' : ''; ?>
							<?php echo $terminalFull ? 'disabled aria-disabled="true"' : ''; ?>>
						<?php endif; ?>
						<?php if ($fHint !== ''): ?>
						<p id="<?php p($fId . '-hint'); ?>" class="bck-license-field__hint"><?php p($fHint); ?></p>
						<?php endif; ?>
					</div>
					<?php endforeach; ?>
				</div>
				<div class="bck-license-form__actions">
					<button type="button" id="bck-terminal-register" class="bck-license-btn bck-license-btn--primary"
						<?php echo $terminalFull ? 'disabled aria-disabled="true"' : ''; ?>>
						<?php p($deviceRegisterLabel); ?>
					</button>
				</div>
				<p id="bck-terminal-count" class="bck-license-seat-count" aria-live="polite">
					<?php p($l->t('%1$d of %2$d device slots in use.', [$terminalUsed, $terminalLimit])); ?>
				</p>
				<p id="bck-terminals-full-hint" class="bck-license-field__hint bck-license-seats-full-hint" role="status" <?php echo $terminalFull ? '' : 'hidden'; ?>>
					<?php p($l->t('All device slots are in use. Revoke a device or upgrade your license.')); ?>
				</p>
			</div>

			<div class="bck-license-seats-table-wrap">
				<table class="bck-license-seats-table bck-license-terminals-table" aria-labelledby="bck-terminal-heading">
					<thead>
						<tr>
							<th scope="col"><?php p($deviceColLabel); ?></th>
							<th scope="col"><?php p($deviceColDetail); ?></th>
							<th scope="col"><?php p($deviceColRegistered); ?></th>
							<th scope="col"><span class="bck-license-sr-only"><?php p($l->t('Actions')); ?></span></th>
						</tr>
					</thead>
					<tbody id="bck-terminal-list-body">
						<?php foreach ($terminals as $term): ?>
							<tr data-device-id="<?php p((string)($term['id'] ?? '')); ?>">
								<td data-label="<?php p($deviceColLabel); ?>"><?php p((string)($term['label'] ?? '')); ?></td>
								<td data-label="<?php p($deviceColDetail); ?>"><?php p((string)($term['meta'] ?? '') !== '' ? (string)$term['meta'] : '—'); ?></td>
								<td data-label="<?php p($deviceColRegistered); ?>"><?php p($formatAssignedAt((string)($term['registeredAt'] ?? $term['assignedAt'] ?? ''))); ?></td>
								<td data-label="<?php p($l->t('Actions')); ?>" class="actions-cell">
									<div class="bck-table-actions bck-license-table-actions" role="group" aria-label="<?php p($l->t('Actions')); ?>">
									<?php if ((string)($_['apiRegenDeviceUrl'] ?? '') !== ''): ?>
									<button type="button" class="bck-license-btn bck-license-btn--secondary bck-license-btn--sm bck-terminal-regen" data-device-id="<?php p((string)($term['id'] ?? '')); ?>">
										<?php p($ds('regenLabel', $l->t('New pairing code'))); ?>
									</button>
									<?php endif; ?>
									<button type="button" class="bck-license-btn bck-license-btn--secondary bck-license-btn--sm bck-terminal-revoke" data-device-id="<?php p((string)($term['id'] ?? '')); ?>">
										<?php p($deviceRevokeLabel); ?>
									</button>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<div id="bck-terminal-empty" class="bck-license-empty-state" <?php echo count($terminals) > 0 ? 'hidden' : ''; ?>>
					<p class="bck-license-empty-state__title"><?php p($deviceEmptyTitle); ?></p>
					<p class="bck-license-empty-state__text"><?php p($deviceEmptyText); ?></p>
				</div>
			</div>
			<?php endif; ?>
		</div>
	</section>
	<?php endif; ?>

	<dialog id="bck-license-clear-modal" class="bck-license-modal" aria-labelledby="bck-license-clear-title">
		<h3 id="bck-license-clear-title"><?php p($l->t('Remove license')); ?></h3>
		<p class="bck-license-modal__hint"><?php p($l->t('Remove the organisation license and revoke all seats and devices? This cannot be undone.')); ?></p>
		<div class="bck-license-modal__actions">
			<button type="button" id="bck-license-clear-cancel" class="bck-license-btn bck-license-btn--secondary"><?php p($l->t('Cancel')); ?></button>
			<button type="button" id="bck-license-clear-confirm" class="bck-license-btn bck-license-btn--danger"><?php p($l->t('Remove license')); ?></button>
		</div>
	</dialog>

	<dialog id="bck-license-confirm-modal" class="bck-license-modal" aria-labelledby="bck-license-confirm-title" aria-describedby="bck-license-confirm-body">
		<h3 id="bck-license-confirm-title"></h3>
		<p class="bck-license-modal__hint" id="bck-license-confirm-body"></p>
		<div class="bck-license-modal__actions">
			<button type="button" id="bck-license-confirm-cancel" class="bck-license-btn bck-license-btn--secondary"></button>
			<button type="button" id="bck-license-confirm-ok" class="bck-license-btn bck-license-btn--danger"></button>
		</div>
	</dialog>

	<dialog id="bck-license-token-modal" class="bck-license-modal" aria-labelledby="bck-license-token-title">
		<h3 id="bck-license-token-title"></h3>
		<p class="bck-license-modal__hint" id="bck-license-token-hint"></p>
		<code class="bck-license-token-value" id="bck-license-token-value" tabindex="0"></code>
		<div class="bck-license-modal__actions">
			<button type="button" id="bck-license-token-copy" class="bck-license-btn bck-license-btn--primary"></button>
			<button type="button" id="bck-license-token-close" class="bck-license-btn bck-license-btn--secondary"></button>
		</div>
	</dialog>
</div>

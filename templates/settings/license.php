<?php
/**
 * Settings → License — thin adapter onto the shared license page
 * (canonical source in _shared/license-page).
 *
 * BookingCheck specifics: the license key persists via settings#put on the
 * 'general' section; clearing uses the dedicated settings#clearLicense
 * route; companion door/manager tokens ride the generic device card.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @license AGPL-3.0-or-later
 */

$l = $l ?? (isset($_['l']) ? $_['l'] : null);
if ($l === null) {
	try {
		$l = \OCP\Util::getL10N('bookingcheck');
	} catch (\Throwable) {
		$l = new class { public function t($s, $p = []) { return $s; } };
	}
}

$licenseStatus = is_array($_['licenseStatus'] ?? null) ? $_['licenseStatus'] : null;
$licenseState = (string)($_['licenseState'] ?? ($licenseStatus['state'] ?? ''));
$validUntil = (string)($licenseStatus['validUntil'] ?? '');

$deviceRows = is_array($_['companionDevices'] ?? null) ? $_['companionDevices'] : [];
$terminals = [];
foreach ($deviceRows as $row) {
	if (!is_array($row) || ($row['revokedAt'] ?? null) !== null) {
		continue;
	}
	$meta = (string)($row['role'] ?? '');
	$scope = (string)($row['offeringIds'] ?? '');
	$terminals[] = [
		'id' => (string)($row['id'] ?? ''),
		'label' => (string)($row['label'] ?? ''),
		'meta' => $scope !== '' && $scope !== '[]' ? $meta . ' · ' . $scope : $meta,
		'registeredAt' => (string)($row['createdAt'] ?? ''),
	];
}
$terminalLimit = (int)($licenseStatus['proSeats'] ?? 0);

$urlGenerator = $_['urlGenerator'] ?? null;
if ($urlGenerator === null) {
	try {
		$urlGenerator = \OCP\Server::get(\OCP\IURLGenerator::class);
	} catch (\Throwable) {
		$urlGenerator = null;
	}
}

$_['license'] = [
	'active' => $licenseState === 'pro',
	'cryptographicallyValid' => $licenseState === 'pro' || $licenseState === 'expired',
	'dateValid' => $licenseState !== 'expired',
	'validUntil' => $validUntil,
	'customerId' => (string)($licenseStatus['customerId'] ?? ''),
];
$_['licenseKey'] = (string)($_['licenseKey'] ?? '');
$_['productCode'] = 'BCK2';
$_['showMobileSeats'] = false;
$_['terminals'] = $terminals;
$_['terminalDevicesUsed'] = count($terminals);
$_['terminalDevicesLimit'] = $terminalLimit;
$_['showTerminal'] = !empty($_['companionAvailable']);
$_['terminalMode'] = 'register';
$_['deviceFields'] = [
	['name' => 'role', 'type' => 'select', 'label' => $l->t('Role'), 'required' => true,
		'options' => [
			['value' => 'door', 'label' => $l->t('Door scanner')],
			['value' => 'manager', 'label' => $l->t('Manager')],
			['value' => 'api', 'label' => $l->t('API access')],
		]],
	['name' => 'offering_ids', 'type' => 'text', 'label' => $l->t('Offering IDs (optional)'),
		'hint' => $l->t('Comma-separated offering IDs this token may access — empty means all.')],
];
$_['deviceStrings'] = [
	'title' => $l->t('Companion devices'),
	'lead' => $l->t('Door scanners and managers sign in with a device token. The token is shown once — copy it into the device right away.'),
];
$_['apiLicenseUrl'] = $urlGenerator ? $urlGenerator->linkToRoute('bookingcheck.settings.put', ['section' => 'general']) : '';
$_['apiClearLicenseUrl'] = $urlGenerator ? $urlGenerator->linkToRoute('bookingcheck.settings.clearLicense') : '';
$_['apiRegisterDeviceUrl'] = $urlGenerator ? $urlGenerator->linkToRoute('bookingcheck.settings.issueDevice') : '';
$_['apiRevokeDeviceUrl'] = $urlGenerator ? $urlGenerator->linkToRoute('bookingcheck.settings.issueDevice') : '';
$_['licenseRenewMailto'] = 'mailto:info@software-by-design.de?subject=' . rawurlencode('BookingCheck license');
$_['productsUrl'] = 'https://nextcloud.software-by-design.de/';
try {
	$_['requesttoken'] = (string)($_['requesttoken'] ?? \OCP\Util::callRegister());
} catch (\Throwable) {
	$_['requesttoken'] = (string)($_['requesttoken'] ?? '');
}

echo '<div id="bck-license">';
include __DIR__ . '/../common/license-page.php';
echo '</div>';

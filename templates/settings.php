<?php

declare(strict_types=1);

/**
 * In-app settings shell — one page per topic (family settings-pages
 * standard). The controller validates `settingsSection` against
 * {@see \OCA\BookingCheck\Support\SettingsCatalog}; this template
 * dispatches through a literal slug → file map, so no request value is
 * ever used to build an include path.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

$l = $_['l'] ?? \OCP\Util::getL10N('bookingcheck');
$section = (string)($_['settingsSection'] ?? '');

$file = match ($section) {
	'access' => 'access.php',
	'payments' => 'payments.php',
	'invoicing' => 'invoicing.php',
	'flow' => 'flow.php',
	'portal' => 'portal.php',
	'integrations' => 'integrations.php',
	'emails' => 'emails.php',
	'license' => 'license.php',
	default => null,
};
if ($file === null) {
	// unreachable — the controller validates before render; fail loud if not
	throw new \LogicException('Unknown settings section: ' . $section);
}

$_['pageTitle'] = $l->t('Settings') . ' — ' . \OCA\BookingCheck\Support\SettingsCatalog::label($l, $section);
$_['pageHelp'] = \OCA\BookingCheck\Support\SettingsCatalog::help($l, $section);
$_['pageIcon'] = \OCA\BookingCheck\Support\SettingsCatalog::icon($section);
$_['settingsSection'] = $section;
\OCA\BookingCheck\Support\Chrome::apply($_, 'settings-' . $section);
include __DIR__ . '/common/page-start.php';
?>
<div class="bck-page-stack" id="bck-settings-root" data-settings-section="<?php p($section); ?>">
	<?php include __DIR__ . '/settings/' . $file; ?>
</div>
<?php include __DIR__ . '/common/page-end.php';

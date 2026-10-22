<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Support;

use OCP\IDateTimeZone;
use OCP\IURLGenerator;
use OCP\Server;

/**
 * Admin page chrome assembly — one place where $_ gets the keys
 * templates/common/page-start.php consumes. Templates set pageTitle,
 * pageIcon, pageHelp, then call Chrome::apply($_, 'navId').
 *
 * Keeps controllers free of chrome plumbing and keeps the contract
 * testable in one place.
 */
final class Chrome
{
	private function __construct()
	{
	}

	/**
	 * @param non-empty-string $route
	 * @param array<string,mixed> $params
	 */
	private static function route(string $route, array $params = []): string
	{
		return Server::get(IURLGenerator::class)->linkToRoute($route, $params);
	}

	/**
	 * @param array<string,mixed> $_ template params (mutated in place)
	 */
	public static function apply(array &$_, string $navId): void
	{
		$r = self::route(...);

		$l10n = Server::get(\OCP\L10N\IFactory::class)->get('bookingcheck');
		$_['pageId'] = (string)($_['pageId'] ?? $navId);
		$_['pageTitle'] = (string)($_['pageTitle'] ?? 'BookingCheck');
		$_['pageHelp'] = (string)($_['pageHelp'] ?? '');
		$_['pageIcon'] = (string)($_['pageIcon'] ?? self::iconFor($navId));
		$_['roleLabel'] = (string)($_['roleLabel'] ?? $l10n->t('Organizer'));
		$_['brandTitle'] = 'BookingCheck';
		$_['brandSubtitle'] = (string)($_['brandSubtitle'] ?? '');
		$_['brandIcon'] = 'clipboard-check';
		$_['urls'] = ['home' => $r('bookingcheck.page.index')];

		$lang = $l10n->getLanguageCode();
		$tz = 'UTC';
		try {
			// per-user zone falls back to the server default inside NC
			$tz = Server::get(IDateTimeZone::class)->getTimeZone()->getName();
		} catch (\Throwable) {
		}
		$_['clientHints'] = ['locale' => $lang, 'htmlLang' => $lang, 'timezone' => $tz];

		$_['navGroups'] = [[
			'label' => '',
			'items' => array_map(
				static fn (array $i): array => $i + ['active' => $i['id'] === $navId],
				[
					['id' => 'overview',  'label' => $l10n->t('Overview'),  'icon' => 'home',            'href' => $r('bookingcheck.dashboard.index')],
					['id' => 'offerings', 'label' => $l10n->t('Offerings'), 'icon' => 'calendar',        'href' => $r('bookingcheck.offering.index')],
					['id' => 'orders',    'label' => $l10n->t('Orders'),    'icon' => 'clipboard-list',  'href' => $r('bookingcheck.order.index')],
					['id' => 'roster',    'label' => $l10n->t('Roster'),    'icon' => 'users',           'href' => $r('bookingcheck.roster.index')],
					['id' => 'scan',      'label' => $l10n->t('Check-in'),  'icon' => 'qr-code',         'href' => $r('bookingcheck.scan.index')],
					['id' => 'waitlist',  'label' => $l10n->t('Waitlist'),  'icon' => 'repeat',          'href' => $r('bookingcheck.waitlist.index')],
				],
			),
		], [
			'label' => $l10n->t('Sales'),
			'items' => array_map(
				static fn (array $i): array => $i + ['active' => $i['id'] === $navId],
				[
					['id' => 'vouchers',  'label' => $l10n->t('Vouchers'),  'icon' => 'file-check',      'href' => $r('bookingcheck.voucher.index')],
					['id' => 'promos',    'label' => $l10n->t('Promotions'),'icon' => 'activity',        'href' => $r('bookingcheck.promo.index')],
					['id' => 'reviews',   'label' => $l10n->t('Reviews'),   'icon' => 'graduation-cap',  'href' => $r('bookingcheck.review.index')],
				],
			),
		], [
			'label' => $l10n->t('Manage'),
			'items' => array_map(
				static fn (array $i): array => $i + ['active' => $i['id'] === $navId],
				[
					['id' => 'resources', 'label' => $l10n->t('Resources'), 'icon' => 'layout-grid',     'href' => $r('bookingcheck.resource.index')],
					['id' => 'reports',   'label' => $l10n->t('Reports'),   'icon' => 'file-text',       'href' => $r('bookingcheck.report.index')],
					['id' => 'audit',     'label' => $l10n->t('Audit log'), 'icon' => 'scroll-text',     'href' => $r('bookingcheck.audit.index')],
					['id' => 'webhooks',  'label' => $l10n->t('Webhooks'),  'icon' => 'activity',        'href' => $r('bookingcheck.webhook.index')],
					['id' => 'support',   'label' => $l10n->t('Support & us'), 'icon' => 'heart-handshake', 'href' => $r('bookingcheck.page.supportUs')],
				],
			),
		]];

		$_['footerPartial'] = dirname(__DIR__, 2) . '/templates/parts/feedback-nav-footer.php';

		// Settings section nav — app administrators only. Instance
		// configuration must never advertise itself to allowlisted
		// organizers who cannot save it (dead-end + existence noise).
		try {
			$uid = (string)(Server::get(\OCP\IUserSession::class)->getUser()?->getUID() ?? '');
			$l10n = Server::get(\OCP\L10N\IFactory::class)->get('bookingcheck');
			if ($uid !== '' && Server::get(\OCA\BookingCheck\Service\AccessControlService::class)->isAppAdmin($uid)) {
				$items = [];
				foreach (\OCA\BookingCheck\Support\SettingsCatalog::SECTIONS as $section) {
					$id = 'settings-' . $section;
					$items[] = [
						'id' => $id,
						'label' => \OCA\BookingCheck\Support\SettingsCatalog::label($l10n, $section),
						'icon' => \OCA\BookingCheck\Support\SettingsCatalog::icon($section),
						'href' => $r('bookingcheck.page.settingsSection', ['section' => $section]),
						'active' => $navId === $id,
					];
				}
				$_['settingsNav'] = $items;
			}
		} catch (\Throwable) {
		}

		// shared admin behaviour (delete buttons, order actions, scan, roster)
		\OCP\Util::addScript('bookingcheck', 'pages/admin');
		if (str_starts_with($navId, 'settings-')) {
			\OCP\Util::addScript('bookingcheck', 'pages/settings');
		}
	}

	private static function iconFor(string $navId): string
	{
		return match ($navId) {
			'overview' => 'home',
			'offerings' => 'calendar',
			'orders' => 'clipboard-list',
			'roster' => 'users',
			'scan' => 'qr-code',
			'waitlist' => 'repeat',
			'vouchers' => 'file-check',
			'promos', 'webhooks' => 'activity',
			'reviews' => 'graduation-cap',
			'resources' => 'layout-grid',
			'reports' => 'file-text',
			'audit' => 'scroll-text',
			'support' => 'heart-handshake',
			default => 'clipboard-check',
		};
	}
}

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
	 * @param array<string,mixed> $_ template params (mutated in place)
	 */
	public static function apply(array &$_, string $navId): void
	{
		$url = Server::get(IURLGenerator::class);
		$r = static fn (string $route): string => $url->linkToRoute($route);

		$_['pageId'] = (string)($_['pageId'] ?? $navId);
		$_['pageTitle'] = (string)($_['pageTitle'] ?? 'BookingCheck');
		$_['pageHelp'] = (string)($_['pageHelp'] ?? '');
		$_['pageIcon'] = (string)($_['pageIcon'] ?? self::iconFor($navId));
		$_['roleLabel'] = (string)($_['roleLabel'] ?? 'Organizer');
		$_['brandTitle'] = 'BookingCheck';
		$_['brandSubtitle'] = (string)($_['brandSubtitle'] ?? '');
		$_['brandIcon'] = 'clipboard-check';
		$_['urls'] = ['home' => $r('bookingcheck.page.index')];

		$lang = 'en';
		try {
			$lang = Server::get(\OCP\IL10N::class)->getLanguageCode();
		} catch (\Throwable) {
		}
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
					['id' => 'overview',  'label' => 'Overview',  'icon' => 'home',            'href' => $r('bookingcheck.dashboard.index')],
					['id' => 'offerings', 'label' => 'Offerings', 'icon' => 'calendar',        'href' => $r('bookingcheck.offering.index')],
					['id' => 'orders',    'label' => 'Orders',    'icon' => 'clipboard-list',  'href' => $r('bookingcheck.order.index')],
					['id' => 'roster',    'label' => 'Roster',    'icon' => 'users',           'href' => $r('bookingcheck.roster.index')],
					['id' => 'scan',      'label' => 'Check-in',  'icon' => 'qr-code',         'href' => $r('bookingcheck.scan.index')],
					['id' => 'waitlist',  'label' => 'Waitlist',  'icon' => 'repeat',          'href' => $r('bookingcheck.waitlist.index')],
				],
			),
		], [
			'label' => 'Sales',
			'items' => array_map(
				static fn (array $i): array => $i + ['active' => $i['id'] === $navId],
				[
					['id' => 'vouchers',  'label' => 'Vouchers',  'icon' => 'file-check',      'href' => $r('bookingcheck.voucher.index')],
					['id' => 'promos',    'label' => 'Promotions','icon' => 'activity',        'href' => $r('bookingcheck.promo.index')],
					['id' => 'reviews',   'label' => 'Reviews',   'icon' => 'graduation-cap',  'href' => $r('bookingcheck.review.index')],
				],
			),
		], [
			'label' => 'Manage',
			'items' => array_map(
				static fn (array $i): array => $i + ['active' => $i['id'] === $navId],
				[
					['id' => 'resources', 'label' => 'Resources', 'icon' => 'layout-grid',     'href' => $r('bookingcheck.resource.index')],
					['id' => 'reports',   'label' => 'Reports',   'icon' => 'file-text',       'href' => $r('bookingcheck.report.index')],
					['id' => 'audit',     'label' => 'Audit log', 'icon' => 'scroll-text',     'href' => $r('bookingcheck.audit.index')],
					['id' => 'webhooks',  'label' => 'Webhooks',  'icon' => 'activity',        'href' => $r('bookingcheck.webhook.index')],
				],
			),
		]];

		$_['footerPartial'] = dirname(__DIR__, 2) . '/templates/parts/feedback-nav-footer.php';

		// shared admin behaviour (delete buttons, order actions, scan, roster)
		\OCP\Util::addScript('bookingcheck', 'pages/admin');
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
			default => 'clipboard-check',
		};
	}
}

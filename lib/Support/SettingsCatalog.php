<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Support;

use OCP\IL10N;

/**
 * In-app settings catalog — single source of truth for the per-topic
 * settings pages (family settings-pages standard: /settings/{section},
 * one page per topic, sidebar group = section navigation).
 *
 * Every section is app-admin-only: this catalog governs instance
 * configuration (PSP credentials, tax identity, access policy, license),
 * not organizer day-to-day work.
 */
final class SettingsCatalog
{
	/** Ordered section ids — index route redirects to the first one. */
	public const SECTIONS = [
		'access', 'payments', 'invoicing', 'flow',
		'portal', 'integrations', 'emails', 'license',
	];

	private function __construct()
	{
	}

	public static function isSection(string $section): bool
	{
		return in_array($section, self::SECTIONS, true);
	}

	public static function label(IL10N $l, string $section): string
	{
		return match ($section) {
			'access' => $l->t('Access control'),
			'payments' => $l->t('Payments'),
			'invoicing' => $l->t('Invoicing & tax'),
			'flow' => $l->t('Booking flow'),
			'portal' => $l->t('Portal & sharing'),
			'integrations' => $l->t('Integrations'),
			'emails' => $l->t('Emails'),
			'license' => $l->t('License'),
			default => $section,
		};
	}

	public static function help(IL10N $l, string $section): string
	{
		return match ($section) {
			'access' => $l->t('Who may open the organizer surface — and who configures BookingCheck.'),
			'payments' => $l->t('Which payment provider processes checkouts. Webhooks confirm payment — the redirect is never trusted.'),
			'invoicing' => $l->t('Seller identity and tax handling on invoices.'),
			'flow' => $l->t('Holds and lifetimes for the booking lifecycle.'),
			'portal' => $l->t('Public catalog, customer portal and feed endpoints.'),
			'integrations' => $l->t('Connected apps and optional upload scanning.'),
			'emails' => $l->t('Notification preferences and outgoing mail text.'),
			'license' => $l->t('Pro license key and live verification state.'),
			default => '',
		};
	}

	public static function icon(string $section): string
	{
		// IconCatalog names — the shared synced set only, no app-local strokes.
		return match ($section) {
			'access' => 'lock',
			'payments' => 'file-check',
			'invoicing' => 'file-text',
			'flow' => 'repeat',
			'portal' => 'external-link',
			'integrations' => 'layout-grid',
			'emails' => 'inbox',
			'license' => 'shield',
			default => 'settings',
		};
	}
}

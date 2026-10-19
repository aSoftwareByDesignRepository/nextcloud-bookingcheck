<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Single source for the app's shell asset set (Check-family pattern).
 * Shared component: apps/_shared/check-shell (synced — do not edit in-app).
 *
 * The access-denied surface renders via middleware before the controller —
 * it must register the same styles or it renders unstyled.
 */

namespace OCA\BookingCheck\Support;

use OCA\BookingCheck\AppInfo\Application;
use OCP\Util;

final class AssetRegistrar
{
	/**
	 * Full shared stylesheet layer, in dependency order:
	 * tokens → chrome/layout → controls/patterns → surfaces → app overrides.
	 */
	public static function registerStyles(): void
	{
		// Make l10n/{lang}.json available to OC.L10N.translate() in page JS.
		Util::addTranslations(Application::APP_ID);
		foreach ([
			'common/tokens', 'common/shell-chrome', 'common/app-layout', 'common/navigation',
			'common/mobile-nav', 'common/form-controls', 'common/page-patterns',
			'common/notification-surfaces', 'common/dialogs', 'common/switch-field',
			'common/badges', 'common/field-errors', 'common/surfaces', 'app',
		] as $style) {
			Util::addStyle(Application::APP_ID, $style);
		}
	}

	/**
	 * Shared script layer: toasts → field errors → app feedback →
	 * mobile nav → kernel (BCK). Page scripts register separately.
	 */
	public static function registerScripts(): void
	{
		foreach (['common/toasts', 'common/field-errors', 'common/app-feedback', 'common/mobile-nav', 'app'] as $script) {
			Util::addScript(Application::APP_ID, $script);
		}
	}

	/** Register the shell + a page script (js/pages/<name>.js). */
	public static function register(string $pageScript): void
	{
		self::registerStyles();
		self::registerScripts();
		if ($pageScript !== '') {
			Util::addScript(Application::APP_ID, 'pages/' . $pageScript);
		}
	}

	/**
	 * Guest-facing pages (PublicTemplateResponse) get no admin shell — but
	 * they still need the token/surface styles and the BCK kernel (appBase,
	 * toast) or every public page script silently no-ops.
	 */
	public static function registerPublic(string $pageScript = ''): void
	{
		self::registerStyles();
		foreach (['common/toasts', 'common/field-errors', 'app'] as $script) {
			Util::addScript(Application::APP_ID, $script);
		}
		if ($pageScript !== '') {
			Util::addScript(Application::APP_ID, 'pages/' . $pageScript);
		}
	}
}

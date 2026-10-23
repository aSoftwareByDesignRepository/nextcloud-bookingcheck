<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Support;

use OCA\BookingCheck\AppInfo\Application;
use OCP\IL10N;
use OCP\IRequest;
use OCP\L10N\IFactory;

/**
 * Localization at the error-envelope serialization boundary
 * (l10n_unlocalized_error_surface).
 *
 * Every 'message' and 'fields' string that leaves the app in a
 * {success:false,…} envelope is authored in English at the throw/call
 * site; this helper translates it in the request's own locale so toasts,
 * inline field errors and the buyer embed never render raw English.
 *
 * Locale resolution mirrors the app's existing convention: buyer-facing
 * /pub/ calls carry an explicit `locale` param (the embed sends the
 * buyer's chosen language — same source as embedStrings()); everything
 * else resolves through the l10n factory, i.e. the session user's
 * language for staff /api calls and the instance default elsewhere.
 */
final class ErrorText
{
	public static function l10nFor(IRequest $request): IL10N
	{
		$factory = \OCP\Server::get(IFactory::class);
		$locale = $request->getParam('locale');
		if (is_string($locale) && trim($locale) !== ''
			&& str_contains((string)$request->getPathInfo(), '/pub/')) {
			return $factory->get(Application::APP_ID, Locales::canonicalize($locale));
		}
		return $factory->get(Application::APP_ID);
	}

	/**
	 * Translate one wire-bound string. Unknown msgids pass through
	 * unchanged (English source — same as before). Text containing a
	 * literal '%' is never fed to the l10n pipeline: L10NString's
	 * __toString() vsprintf()s unconditionally and an unparameterized
	 * percent fatals (l10n_sprintf_literal_percent_crash).
	 */
	public static function t(IL10N $l, string $text): string
	{
		if ($text === '' || str_contains($text, '%')) {
			return $text;
		}
		return $l->t($text);
	}

	/**
	 * @param array<string,string> $fields
	 * @return array<string,string>
	 */
	public static function fields(IL10N $l, array $fields): array
	{
		$out = [];
		foreach ($fields as $name => $message) {
			$out[$name] = is_string($message) ? self::t($l, $message) : $message;
		}
		return $out;
	}
}

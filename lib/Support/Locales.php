<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Support;

/**
 * Canonical locale resolution for the shipped catalogs.
 *
 * Request locales arrive in every shape browsers produce ('pt-BR',
 * 'pt_br', 'PT', 'en-US'). Anything that resolves a catalog or stores a
 * buyer/waiter locale must map through here so UI language, stored
 * locale and mail language agree: 'pt-BR'/'pt' → 'pt_BR', other valid
 * shapes pass through (the l10n factory falls back to English for
 * unshipped languages), invalid input → 'en'.
 */
final class Locales
{
	/** @var array<string,string> lowercased request locale → shipped catalog */
	private const SHIPPED = [
		'en' => 'en', 'de' => 'de', 'fr' => 'fr', 'es' => 'es', 'it' => 'it',
		'nl' => 'nl', 'da' => 'da', 'nb' => 'nb', 'pl' => 'pl',
		'pt_br' => 'pt_BR', 'pt' => 'pt_BR', 'sv' => 'sv',
	];

	public static function canonicalize(mixed $locale): string
	{
		if (!is_string($locale)) {
			return 'en';
		}
		$lang = strtolower(substr(trim($locale), 0, 10));
		if (isset(self::SHIPPED[$lang])) {
			return self::SHIPPED[$lang];
		}
		$underscored = str_replace('-', '_', $lang);
		if (isset(self::SHIPPED[$underscored])) {
			return self::SHIPPED[$underscored];
		}
		// Valid shape but not shipped (e.g. 'ja', 'en-us' → region-stripped
		// 'en' is already handled above only when exact — strip the region
		// here so 'de-at' still lands on the 'de' catalog).
		$primary = explode('_', $underscored)[0];
		if (isset(self::SHIPPED[$primary])) {
			return self::SHIPPED[$primary];
		}
		return preg_match('/^[a-z]{2}(_[a-z]{2})?$/', $underscored) === 1
			? $underscored
			: 'en';
	}
}

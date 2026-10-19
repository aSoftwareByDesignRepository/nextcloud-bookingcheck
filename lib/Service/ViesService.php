<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * EU VAT-ID verification for B2B reverse charge (§3a UStG / Art. 196).
 *
 * Fail-closed on tax: only a VIES 'valid' result flips an order to
 * reverse charge. Network down, malformed replies, or the live check
 * being disabled all degrade to 'unchecked' — the buyer pays normal
 * VAT, never a wrongly-zeroed invoice.
 */
class ViesService
{
	/** ISO-2 of the EU VAT area ('XI' = Northern Ireland protocol). */
	private const EU = [
		'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE',
		'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT',
		'RO', 'SK', 'SI', 'ES', 'SE', 'XI',
	];

	private const VIES_URL = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';

	public function __construct(
		private readonly IClientService $http,
		private readonly SettingsService $settings,
		private readonly LoggerInterface $logger,
	) {
	}

	public static function isEuCountry(string $iso2): bool
	{
		return in_array(strtoupper($iso2), self::EU, true);
	}

	/**
	 * @return 'valid'|'invalid'|'unchecked'
	 */
	public function verify(string $country, string $vatId): string
	{
		$country = strtoupper($country);
		if (!self::isEuCountry($country)) {
			return 'invalid';
		}
		if ($this->settings->get('vies_online') !== '1') {
			return 'unchecked'; // live check disabled — never block checkout
		}
		try {
			$resp = $this->http->newClient()->post(self::VIES_URL, [
				'json' => ['countryCode' => $country, 'vatNumber' => substr($vatId, 2)],
				'timeout' => 5,
				'headers' => ['Accept' => 'application/json'],
			]);
			$body = json_decode((string)$resp->getBody(), true);
			if (!is_array($body) || !array_key_exists('valid', $body)) {
				return 'unchecked';
			}
			return $body['valid'] === true ? 'valid' : 'invalid';
		} catch (\Throwable $e) {
			$this->logger->info('VIES lookup failed', ['exception' => $e]);
			return 'unchecked';
		}
	}
}

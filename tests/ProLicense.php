<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Tests;

use OCA\BookingCheck\Config\VendorPublicKey;

/**
 * Mints a REAL BCK2 license for the test process — the codec's trust
 * anchor is swappable only under PHPUnit (envOverrideAllowed), so the
 * exercise path is production-identical: parse → verify → gate.
 */
final class ProLicense
{
	private static ?string $keypair = null;

	public static function enable(): void
	{
		if (self::$keypair === null) {
			self::$keypair = sodium_crypto_sign_keypair();
		}
		$pub = sodium_crypto_sign_publickey(self::$keypair);
		putenv('BCK_VENDOR_PUBLIC_KEY_B64=' . VendorPublicKey::base64urlEncode($pub));

		$payload = json_encode([
			'v' => 2,
			'product' => 'bookingcheck',
			'customerId' => 'itest',
			'issuedAt' => '2026-01-01',
			'validUntil' => '2099-12-31',
			'proSeats' => 100,
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$sig = sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey(self::$keypair));
		$wire = 'BCK2.' . VendorPublicKey::base64urlEncode($payload)
			. '.' . VendorPublicKey::base64urlEncode($sig);

		\OC::$server->get(\OCP\IConfig::class)
			->setAppValue('bookingcheck', 'license_key', $wire);
	}

	public static function disable(): void
	{
		\OC::$server->get(\OCP\IConfig::class)
			->deleteAppValue('bookingcheck', 'license_key');
		putenv('BCK_VENDOR_PUBLIC_KEY_B64');
	}
}

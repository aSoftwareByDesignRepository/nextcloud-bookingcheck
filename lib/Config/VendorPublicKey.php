<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Config;

/**
 * Embedded vendor Ed25519 public key (32 bytes) for BCK2 verification.
 * Same vendor key family as SbdLicenseOps issues — never fetched remotely.
 *
 * Env override `BCK_VENDOR_PUBLIC_KEY_B64` only under PHPUnit /
 * BCK_ALLOW_VENDOR_KEY_OVERRIDE=1 for test harnesses.
 */
final class VendorPublicKey
{
	/** Production vendor public key — same trust anchor as the Check family. */
	public const DEFAULT_PUBLIC_KEY_B64 = 'naLgi4THUgwJCRoUehq20QU4uJsLVHzuKV04NhkITn8';

	/** Deterministic PHPUnit fixture key (shared Check-family test seed). */
	public const TEST_PUBLIC_KEY_B64 = 'QLPCkorywY7kyNFmR961Euz2vwLMndfllF-3hmG6hnM';

	public static function publicKeyB64(): string
	{
		if (self::envOverrideAllowed()) {
			$fromEnv = getenv('BCK_VENDOR_PUBLIC_KEY_B64');
			if (is_string($fromEnv) && trim($fromEnv) !== '') {
				return trim($fromEnv);
			}
		}
		return self::DEFAULT_PUBLIC_KEY_B64;
	}

	/** @return non-empty-string raw 32-byte key */
	public static function bytes(): string
	{
		$bytes = self::base64urlDecode(self::publicKeyB64());
		if ($bytes === false || strlen($bytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
			throw new \RuntimeException('Vendor public key misconfigured.');
		}
		return $bytes;
	}

	public static function base64urlDecode(string $b64): string|false
	{
		return base64_decode(strtr($b64, '-_', '+/'), true);
	}

	public static function base64urlEncode(string $bin): string
	{
		return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
	}

	private static function envOverrideAllowed(): bool
	{
		if (getenv('BCK_ALLOW_VENDOR_KEY_OVERRIDE') === '1') {
			return true;
		}
		// PHPUnit only — production processes must not swap the trust anchor
		return defined('PHPUNIT_COMPOSER_INSTALL') || getenv('PHPUNIT') === '1';
	}
}

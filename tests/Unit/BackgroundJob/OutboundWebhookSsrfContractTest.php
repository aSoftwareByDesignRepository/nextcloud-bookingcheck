<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit\BackgroundJob;

use PHPUnit\Framework\TestCase;

/**
 * Contract: outbound webhook delivery must never dial an unverified
 * address — the platform local-address guard must be enabled and
 * redirects must never be followed into an unvalidated target.
 */
final class OutboundWebhookSsrfContractTest extends TestCase
{
	public function testDeliveryPinsLocalAddressGuardAndNoRedirects(): void
	{
		$src = (string)file_get_contents(
			__DIR__ . '/../../../lib/BackgroundJob/OutboundWebhookJob.php',
		);

		// The platform guard verifies the address actually dialed — without
		// it, the DNS pre-check is a TOCTOU window (rebinding bypass).
		self::assertStringContainsString(
			"'allow_local_address' => false",
			$src,
			'webhook posts must enable the Nextcloud local-address SSRF guard',
		);
		self::assertStringContainsString(
			"'allow_redirects' => false",
			$src,
			'webhook posts must not follow redirects into unvalidated hosts',
		);
	}

	public function testUrlIsRevalidatedAtDeliveryTime(): void
	{
		$src = (string)file_get_contents(
			__DIR__ . '/../../../lib/BackgroundJob/OutboundWebhookJob.php',
		);
		self::assertStringContainsString(
			'assertUrlAllowed(',
			$src,
			'the URL must be re-validated at send time, not only at save time',
		);
	}
}

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Tests\Unit\Service;

use OCA\BookingCheck\Config\VendorPublicKey;
use OCA\BookingCheck\Service\LicenseService;
use OCA\BookingCheck\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/** License state matrix + the spec's 90-day post-expiry portal grace. */
final class LicenseServiceTest extends TestCase
{
	private static ?string $keypair = null;

	protected function setUp(): void
	{
		parent::setUp();
		if (self::$keypair === null) {
			self::$keypair = sodium_crypto_sign_keypair();
		}
		putenv('BCK_VENDOR_PUBLIC_KEY_B64='
			. VendorPublicKey::base64urlEncode(sodium_crypto_sign_publickey(self::$keypair)));
	}

	protected function tearDown(): void
	{
		putenv('BCK_VENDOR_PUBLIC_KEY_B64');
		parent::tearDown();
	}

	private function wire(string $validUntil): string
	{
		$payload = json_encode([
			'v' => 2, 'product' => 'bookingcheck', 'customerId' => 'utest',
			'issuedAt' => '2026-01-01', 'validUntil' => $validUntil, 'proSeats' => 10,
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$sig = sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey(self::$keypair));
		return 'BCK2.' . VendorPublicKey::base64urlEncode($payload)
			. '.' . VendorPublicKey::base64urlEncode($sig);
	}

	private function service(string $wire): LicenseService
	{
		$settings = $this->createMock(SettingsService::class);
		$settings->method('get')->willReturnCallback(
			static fn (string $k, $d = '') => $k === 'license_key' ? $wire : $d);
		return new LicenseService($settings);
	}

	public function testValidLicenseIsPro(): void
	{
		$s = $this->service($this->wire('2099-12-31'));
		self::assertTrue($s->isPro());
		self::assertTrue($s->hasFeature('portal'));
		self::assertTrue($s->hasFeature('vouchers'));
	}

	/** Spec: portal read access persists 90d post-expiry — buyers keep
	 *  tickets/materials while seller-side Pro controls are dead. */
	public function testExpiredWithinGraceKeepsPortal(): void
	{
		$lapsed = (new \DateTimeImmutable('today'))->modify('-10 days')->format('Y-m-d');
		$s = $this->service($this->wire($lapsed));
		self::assertFalse($s->isPro());
		self::assertTrue($s->hasFeature('portal'), '90-day buyer-protection grace');
		self::assertFalse($s->hasFeature('vouchers'), 'seller controls die immediately');
	}

	public function testGraceEndsAtNinetyDays(): void
	{
		$edge = (new \DateTimeImmutable('today'))->modify('-90 days')->format('Y-m-d');
		$s = $this->service($this->wire($edge));
		self::assertTrue($s->hasFeature('portal'), 'day-90 is inside the grace');

		$past = (new \DateTimeImmutable('today'))->modify('-91 days')->format('Y-m-d');
		$s2 = $this->service($this->wire($past));
		self::assertFalse($s2->hasFeature('portal'), 'day-91 closes the grace');
	}

	public function testNeverLicensedHasNoPortal(): void
	{
		$s = $this->service('');
		self::assertFalse($s->hasFeature('portal'));
		self::assertFalse($s->isPro());
	}

	public function testUnknownFeatureIsFreePath(): void
	{
		$s = $this->service('');
		self::assertTrue($s->hasFeature('not_a_gate'));
	}
}

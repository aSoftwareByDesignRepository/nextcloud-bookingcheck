<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit\Psp;

use OCA\BookingCheck\Exception\PspException;
use OCA\BookingCheck\Psp\TestProvider;
use OCA\BookingCheck\Psp\VerifiedEvent;
use OCA\BookingCheck\Service\SettingsService;
use OCP\IURLGenerator;
use Test\TestCase;

final class TestProviderVerifyTest extends TestCase
{
	private function provider(string $secret): TestProvider
	{
		$settings = $this->createMock(SettingsService::class);
		$settings->method('get')->willReturnCallback(
			fn (string $k) => $k === 'psp_test_secret' ? $secret : '');
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('http://test/checkout');
		return new TestProvider($settings, $url);
	}

	public function testGoodSignatureVerifiesPaid(): void
	{
		$secret = 'unit-secret';
		$body = json_encode(['id' => 'e1', 'psp_ref' => 'p1', 'status' => 'paid',
			'amount_cents' => 100, 'currency' => 'EUR']);
		$ev = $this->provider($secret)->verifyRaw($body, hash_hmac('sha256', $body, $secret));
		self::assertSame(VerifiedEvent::KIND_PAID, $ev->kind);
		self::assertSame('p1', $ev->pspRef);
		self::assertSame(100, $ev->amountCents);
	}

	public function testBadSignatureRejected(): void
	{
		$this->expectException(PspException::class);
		$this->provider('unit-secret')->verifyRaw('{}', 'deadbeef');
	}

	public function testMissingSecretRefuses(): void
	{
		$this->expectException(PspException::class);
		$this->provider('')->verifyRaw('{}', 'x');
	}

	public function testMalformedBodyRejected(): void
	{
		$body = '{"foo":1}';
		$this->expectException(PspException::class);
		$this->provider('s')->verifyRaw($body, hash_hmac('sha256', $body, 's'));
	}

	public function testTamperedBodyRejected(): void
	{
		$secret = 'unit-secret';
		$body = json_encode(['id' => 'e2', 'psp_ref' => 'p9', 'status' => 'failed']);
		$sig = hash_hmac('sha256', $body, $secret);
		// attacker flips status to paid but keeps old signature
		$forged = json_encode(['id' => 'e2', 'psp_ref' => 'p9', 'status' => 'paid']);
		$this->expectException(PspException::class);
		$this->provider($secret)->verifyRaw($forged, $sig);
	}
}

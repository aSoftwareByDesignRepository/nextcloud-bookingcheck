<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit\Psp;

use OCA\BookingCheck\Exception\PspException;
use OCA\BookingCheck\Psp\StripeProvider;
use OCA\BookingCheck\Service\SettingsService;
use OCP\Http\Client\IClientService;
use Test\TestCase;

/** The Stripe HMAC seam — timestamp tolerance + v1 signature over ts.payload. */
final class StripeSignatureTest extends TestCase
{
	private function invoke(string $payload, string $header, string $secret): void
	{
		$p = new StripeProvider(
			$this->createMock(IClientService::class),
			$this->createMock(SettingsService::class));
		$m = new \ReflectionMethod($p, 'verifySignature');
		$m->invoke($p, $payload, $header, $secret);
	}

	private function header(string $payload, string $secret, ?int $ts = null): string
	{
		$ts = $ts ?? time();
		return 't=' . $ts . ',v1=' . hash_hmac('sha256', $ts . '.' . $payload, $secret);
	}

	public function testValidSignaturePasses(): void
	{
		$body = '{"id":"evt_x","type":"checkout.session.completed"}';
		$this->invoke($body, $this->header($body, 'whsec_test'), 'whsec_test');
		self::assertTrue(true); // reached without throw
	}

	public function testWrongSecretRejected(): void
	{
		$body = '{"id":"evt_x"}';
		$this->expectException(PspException::class);
		$this->invoke($body, $this->header($body, 'other-secret'), 'whsec_test');
	}

	public function testStaleTimestampRejected(): void
	{
		$body = '{"id":"evt_x"}';
		$this->expectException(PspException::class);
		$this->invoke($body, $this->header($body, 'whsec_test', time() - 600), 'whsec_test');
	}

	public function testMalformedHeaderRejected(): void
	{
		$this->expectException(PspException::class);
		$this->invoke('{}', 'garbage', 'whsec_test');
	}
}

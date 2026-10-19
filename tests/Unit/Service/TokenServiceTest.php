<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit\Service;

use OCA\BookingCheck\Service\TokenService;
use Test\TestCase;

final class TokenServiceTest extends TestCase
{
	public function testMintIsUrlSafeAndDistinct(): void
	{
		$t = new TokenService();
		[$a, $ha] = $t->mint();
		[$b, ] = $t->mint();
		self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32,64}$/', $a);
		self::assertNotSame($a, $b);
		self::assertSame(hash('sha256', $a), $ha);
	}

	public function testVerifyConstantTimeShape(): void
	{
		$t = new TokenService();
		[$tok, $hash] = $t->mint();
		self::assertTrue($t->verify($tok, $hash));
		self::assertFalse($t->verify($tok . 'x', $hash));
		self::assertFalse($t->verify($tok, str_repeat('0', 64)));
	}

	public function testOtpIsSixDigits(): void
	{
		$t = new TokenService();
		for ($i = 0; $i < 50; $i++) {
			self::assertMatchesRegularExpression('/^\d{6}$/', $t->mintOtp());
		}
	}

	public function testPublicRefFormat(): void
	{
		$t = new TokenService();
		$ref = $t->publicRef('BCK', 2026);
		self::assertMatchesRegularExpression('/^BCK-2026-[A-Z0-9]{6}$/', $ref);
	}
}

<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Integration;

use OCA\BookingCheck\Service\OtpService;
use OCA\BookingCheck\Service\PortalSessionService;
use OCA\BookingCheck\Service\PricingService;
use OCA\BookingCheck\Service\TokenService;
use OCP\IDBConnection;
use Test\TestCase;

final class AuthAndPricingIntegrationTest extends TestCase
{
	private IDBConnection $db;
	private array $emails = [];

	protected function setUp(): void
	{
		parent::setUp();
		$this->db = \OC::$server->get(IDBConnection::class);
	}

	protected function tearDown(): void
	{
		foreach ($this->emails as $e) {
			foreach (['bc_otp' => 'email', 'bc_portal_sess' => 'email'] as $t => $col) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete($t)->where($qb->expr()->eq($col, $qb->createNamedParameter($e)))->executeStatement();
			}
		}
		parent::tearDown();
	}

	public function testOtpIssueVerifyConsume(): void
	{
		$email = 'otp-' . bin2hex(random_bytes(4)) . '@x.test';
		$this->emails[] = $email;
		$otp = \OC::$server->get(OtpService::class);

		[$code, $magic] = $otp->issue($email, '127.0.0.1');
		self::assertMatchesRegularExpression('/^\d{6}$/', $code);

		self::assertTrue($otp->verify($email, $code));
		self::assertFalse($otp->verify($email, $code), 'consumed code must not verify twice');
	}

	public function testOtpWrongCodeBurnsAttempts(): void
	{
		$email = 'otp-' . bin2hex(random_bytes(4)) . '@x.test';
		$this->emails[] = $email;
		$otp = \OC::$server->get(OtpService::class);
		[$code, ] = $otp->issue($email, '127.0.0.1');

		for ($i = 0; $i < OtpService::MAX_ATTEMPTS; $i++) {
			self::assertFalse($otp->verify($email, '000000' === $code ? '111111' : '000000'));
		}
		// after MAX_ATTEMPTS even the right code is dead
		self::assertFalse($otp->verify($email, $code), 'attempt exhaustion must kill the code');
	}

	public function testMagicTokenSingleUse(): void
	{
		$email = 'otp-' . bin2hex(random_bytes(4)) . '@x.test';
		$this->emails[] = $email;
		$otp = \OC::$server->get(OtpService::class);
		[, $magic] = $otp->issue($email, '127.0.0.1');

		self::assertSame($email, $otp->consumeMagic($magic));
		self::assertNull($otp->consumeMagic($magic), 'magic link must be single-use');
	}

	public function testOtpIssueInvalidatesPrior(): void
	{
		$email = 'otp-' . bin2hex(random_bytes(4)) . '@x.test';
		$this->emails[] = $email;
		$otp = \OC::$server->get(OtpService::class);
		[$code1] = $otp->issue($email, '127.0.0.1');
		[$code2] = $otp->issue($email, '127.0.0.1');
		self::assertNotSame($code1, $code2);
		self::assertFalse($otp->verify($email, $code1), 'old code must be dead after re-issue');
		self::assertTrue($otp->verify($email, $code2));
	}

	public function testPortalSessionLifecycle(): void
	{
		$email = 'sess-' . bin2hex(random_bytes(4)) . '@x.test';
		$this->emails[] = $email;
		$sess = \OC::$server->get(PortalSessionService::class);

		[$token] = $sess->create($email, 'phpunit');
		self::assertSame($email, $sess->resolve($token));
		$sess->revoke($token);
		self::assertNull($sess->resolve($token), 'revoked session must not resolve');
		self::assertNull($sess->resolve('nonexistent-token-xyz'));
	}

	public function testPricingServerSideOnly(): void
	{
		$pricing = \OC::$server->get(PricingService::class);
		// offering row shape = what OrderService builds from the DB entity
		$offering = ['id' => 0, 'price_cents' => 2500, 'currency' => 'EUR', 'vat_bp' => 1900];
		$q = $pricing->price($offering, [['target_type' => 'event', 'target_id' => 1, 'seats' => 2]]);
		self::assertSame(5000, $q['total']);
		self::assertSame('EUR', $q['currency']);
		[$net, $vat] = PricingService::splitVat(5000, 1900);
		self::assertSame(5000, $net + $vat);
		self::assertSame(798, $vat); // 19% of gross-inclusive 5000 → 798.32 → 798
	}

	public function testPricingRejectsGarbage(): void
	{
		$pricing = \OC::$server->get(PricingService::class);
		$offering = ['id' => 0, 'price_cents' => 2500, 'currency' => 'EUR', 'vat_bp' => 1900];
		$this->expectException(\OCA\BookingCheck\Exception\ValidationException::class);
		$pricing->price($offering, [['target_type' => 'event', 'target_id' => 1, 'seats' => 0]]);
	}
}

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

		// idle expiry is ENFORCED: a session past expires_at must die even
		// while the absolute cap is still ahead — resolve() must check it
		[$token2] = $sess->create($email, 'phpunit');
		$db = \OC::$server->get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->update('bc_portal_sess')
			->set('expires_at', $qb->createNamedParameter(
				new \DateTime('-1 minute'), 'datetime'))
			->where($qb->expr()->eq('email', $qb->createNamedParameter($email)))
			->andWhere($qb->expr()->isNull('revoked_at'));
		$qb->executeStatement();
		self::assertNull($sess->resolve($token2),
			'an idle-expired session must not resolve or renew');
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

	public function testSplitVatOddBasisPointsNoFloatTypeError(): void
	{
		// odd vatBp made `(10000+$vatBp)/2` a float → intdiv threw TypeError
		// under strict_types. Must compute for ANY integer rate.
		[$net, $vat] = PricingService::splitVat(1000, 1901);
		self::assertSame(1000, $net + $vat);
		self::assertGreaterThan(0, $vat);
		[$net0, $vat0] = PricingService::splitVat(0, 1901);
		self::assertSame([0, 0], [$net0, $vat0]);
	}

	public function testUsablePromoHonoursWindowAndScope(): void
	{
		$pricing = \OC::$server->get(PricingService::class);
		$code = 'IT-' . strtoupper(bin2hex(random_bytes(3)));
		// promo valid only NEXT week — preview must reject it today
		$ins = $this->db->getQueryBuilder();
		$ins->insert('bc_promo')->values([
			'code' => $ins->createNamedParameter($code),
			'kind' => "'percent'", 'percent_bps' => '1000', 'active' => '1',
			'scope' => "'all'",
			'valid_from' => $ins->createNamedParameter(new \DateTimeImmutable('+7 days'), 'datetime'),
		])->executeStatement();
		$promoId = (int)$this->db->lastInsertId('oc_bc_promo');
		try {
			self::assertNull($pricing->usablePromo($code, 1),
				'not-yet-valid promo must preview as unusable');
		} finally {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('bc_promo')->where($qb->expr()->eq('id',
				$qb->createNamedParameter($promoId)))->executeStatement();
		}
	}

	public function testUsablePromoScopeNeedsOfferingMatch(): void
	{
		$pricing = \OC::$server->get(PricingService::class);
		$code = 'IT-' . strtoupper(bin2hex(random_bytes(3)));
		$ins = $this->db->getQueryBuilder();
		$ins->insert('bc_promo')->values([
			'code' => $ins->createNamedParameter($code),
			'kind' => "'fixed'", 'amount_cents' => '500', 'active' => '1',
			'scope' => "'offerings'",
		])->executeStatement();
		$promoId = (int)$this->db->lastInsertId('oc_bc_promo');
		try {
			// scoped promo with no scope row for this offering → unusable
			self::assertNull($pricing->usablePromo($code, 424242));
		} finally {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('bc_promo')->where($qb->expr()->eq('id',
				$qb->createNamedParameter($promoId)))->executeStatement();
		}
	}
}

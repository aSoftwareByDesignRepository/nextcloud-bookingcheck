<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit\Service;

use OCA\BookingCheck\Db\Otp;
use OCA\BookingCheck\Db\OtpMapper;
use OCA\BookingCheck\Service\OtpService;
use OCA\BookingCheck\Service\SettingsService;
use OCA\BookingCheck\Service\TokenService;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Test\TestCase;

final class OtpServiceTest extends TestCase
{
	private function service(OtpMapper $mapper, IDBConnection $db): OtpService
	{
		$tokens = $this->createMock(TokenService::class);
		$tokens->method('hash')->willReturnCallback(fn (string $v) => 'h_' . $v);
		$tokens->method('verify')->willReturnCallback(
			fn (string $code, string $hash) => $hash === 'h_' . $code);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getInt')->willReturnCallback(fn (string $k, int $d = 0) => $d);
		$settings->method('get')->willReturn('');
		return new OtpService($mapper, $tokens, $settings, $db);
	}

	/** QB mock whose UPDATE affects $affected rows (0 = CAS lost). */
	private function dbUpdating(int $affected): IDBConnection
	{
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('x=y');
		$expr->method('isNull')->willReturn('x IS NULL');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('update')->willReturnSelf();
		$qb->method('set')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn(':p');
		$qb->method('executeStatement')->willReturn($affected);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		return $db;
	}

	/**
	 * Two racing consumers both see the row as unused; the CAS loser must
	 * get null — never a second session for the same magic token.
	 */
	public function testMagicConsumeCasLoserGetsNull(): void
	{
		$otp = new Otp();
		$otp->setId(7);
		$otp->setEmail('u@x.test');

		$mapper = $this->createMock(OtpMapper::class);
		$mapper->method('findByMagicHash')->willReturn($otp);

		$svc = $this->service($mapper, $this->dbUpdating(0));
		self::assertNull($svc->consumeMagic('tok'),
			'CAS loser must not receive the email (would mint a duplicate session)');
	}

	public function testMagicConsumeCasWinnerGetsEmail(): void
	{
		$otp = new Otp();
		$otp->setId(7);
		$otp->setEmail('u@x.test');

		$mapper = $this->createMock(OtpMapper::class);
		$mapper->method('findByMagicHash')->willReturn($otp);

		$svc = $this->service($mapper, $this->dbUpdating(1));
		self::assertSame('u@x.test', $svc->consumeMagic('tok'));
	}

	public function testMagicConsumeUnknownTokenGetsNull(): void
	{
		$mapper = $this->createMock(OtpMapper::class);
		$mapper->method('findByMagicHash')->willReturn(null);

		$svc = $this->service($mapper, $this->dbUpdating(1));
		self::assertNull($svc->consumeMagic('forged'));
	}

	/**
	 * The attempt cap is fenced at SQL level: when the bounded UPDATE
	 * affects 0 rows (cap hit or the code was consumed mid-flight), the
	 * presented code must never be evaluated — verify dies as false.
	 */
	public function testVerifyRefusesWhenAttemptBumpLosesCas(): void
	{
		$otp = new Otp();
		$otp->setId(7);
		$otp->setEmail('u@x.test');
		$otp->setAttempts(OtpService::MAX_ATTEMPTS - 1);
		$otp->setCodeHash('h_123456');

		$mapper = $this->createMock(OtpMapper::class);
		$mapper->method('findActive')->willReturn($otp);

		$svc = $this->service($mapper, $this->dbUpdating(0));
		self::assertFalse($svc->verify('u@x.test', '123456'),
			'a lost attempt CAS must fail closed — the cap is not advisory');
	}

	public function testVerifyEvaluatesWhenAttemptBumpWinsCas(): void
	{
		$otp = new Otp();
		$otp->setId(7);
		$otp->setEmail('u@x.test');
		$otp->setAttempts(0);
		$otp->setCodeHash('h_123456');

		$mapper = $this->createMock(OtpMapper::class);
		$mapper->method('findActive')->willReturn($otp);

		// bump wins (1 row), code matches ('h_' + presented), consume CAS wins
		$svc = $this->service($mapper, $this->dbUpdating(1));
		self::assertTrue($svc->verify('u@x.test', '123456'));
	}
}

<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit\Service;

use OCA\BookingCheck\Db\OrderLineMapper;
use OCA\BookingCheck\Db\ResourceMapper;
use OCA\BookingCheck\Db\Ticket;
use OCA\BookingCheck\Db\TicketMapper;
use OCA\BookingCheck\Service\TicketService;
use OCA\BookingCheck\Service\TokenService;
use OCP\IDBConnection;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

final class TicketServiceTest extends TestCase
{
	private TicketMapper&MockObject $tickets;
	private IDBConnection&MockObject $db;
	private TicketService $svc;

	protected function setUp(): void
	{
		parent::setUp();
		$this->tickets = $this->createMock(TicketMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->svc = new TicketService(
			$this->tickets,
			$this->createMock(OrderLineMapper::class),
			$this->createMock(ResourceMapper::class),
			new TokenService(),
			$this->createMock(ICrypto::class),
			$this->db,
		);
	}

	public function testRevokedTicketCannotCheckIn(): void
	{
		$t = new Ticket();
		$t->setId(7);
		$t->setRevokedAt(new \DateTimeImmutable());
		$this->tickets->method('findByQrHash')->willReturn($t);
		// the CAS update must never run for a dead ticket
		$this->db->expects(self::never())->method('getQueryBuilder');
		$r = $this->svc->checkInByToken('sometoken', 'door:1');
		self::assertFalse($r['ok']);
		self::assertTrue($r['revoked']);
		self::assertSame(7, (int)$r['ticket']->getId());
	}

	public function testRevokeLandingMidScanReportsRevokedNotAlready(): void
	{
		// ticket was live when read, revoked before the CAS landed —
		// the CAS must lose and the door must hear 'revoked', not 'already'
		$t = new Ticket();
		$t->setId(7);
		$this->tickets->method('findByQrHash')->willReturn($t);
		$revoked = new Ticket();
		$revoked->setId(7);
		$revoked->setRevokedAt(new \DateTimeImmutable());
		$this->tickets->method('find')->with(7)->willReturn($revoked);

		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$qb->method('update')->willReturnSelf();
		$qb->method('set')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn('p');
		$qb->method('createFunction')->willReturn('f');
		$qb->method('executeStatement')->willReturn(0); // CAS lost
		$this->db->method('getQueryBuilder')->willReturn($qb);

		$r = $this->svc->checkInByToken('sometoken', 'door:1');
		self::assertFalse($r['ok']);
		self::assertTrue($r['revoked']);
		self::assertFalse($r['already']);
	}

	public function testConcurrentCheckInLoserReportsAlready(): void
	{
		// CAS loses to a concurrent check-in — re-read shows live+checked
		$t = new Ticket();
		$t->setId(7);
		$this->tickets->method('findByQrHash')->willReturn($t);
		$checked = new Ticket();
		$checked->setId(7);
		$checked->setCheckedInAt(new \DateTimeImmutable());
		$this->tickets->method('find')->with(7)->willReturn($checked);

		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$qb->method('update')->willReturnSelf();
		$qb->method('set')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn('p');
		$qb->method('createFunction')->willReturn('f');
		$qb->method('executeStatement')->willReturn(0);
		$this->db->method('getQueryBuilder')->willReturn($qb);

		$r = $this->svc->checkInByToken('sometoken', 'door:1');
		self::assertTrue($r['ok']);
		self::assertTrue($r['already']);
		self::assertFalse($r['revoked']);
	}

	public function testUnknownTokenIsPlainNotFound(): void
	{
		$this->tickets->method('findByQrHash')->willReturn(null);
		$r = $this->svc->checkInByToken('nope', 'door:1');
		self::assertFalse($r['ok']);
		self::assertFalse($r['revoked']);
		self::assertNull($r['ticket']);
	}
}

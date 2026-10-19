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

	public function testUnknownTokenIsPlainNotFound(): void
	{
		$this->tickets->method('findByQrHash')->willReturn(null);
		$r = $this->svc->checkInByToken('nope', 'door:1');
		self::assertFalse($r['ok']);
		self::assertFalse($r['revoked']);
		self::assertNull($r['ticket']);
	}
}

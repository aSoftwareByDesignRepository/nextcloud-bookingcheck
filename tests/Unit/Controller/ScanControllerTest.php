<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit\Controller;

use OCA\BookingCheck\Controller\ScanController;
use OCA\BookingCheck\Db\Order;
use OCA\BookingCheck\Db\OrderMapper;
use OCA\BookingCheck\Db\Ticket;
use OCA\BookingCheck\Service\TicketService;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/** Door lookup is a PREVIEW: it resolves codes but must never check in.
 *  Contract: `code` param (QR payload BCK1:.. or order ref BCK-YYYY-XXXXXXXX),
 *  response keys ticket_id / attendee_name / checked_in / tickets. */
final class ScanControllerTest extends TestCase
{
	private IRequest&MockObject $request;
	private TicketService&MockObject $tickets;
	private OrderMapper&MockObject $orders;
	private IUserSession&MockObject $session;
	private ScanController $ctrl;

	protected function setUp(): void
	{
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->tickets = $this->createMock(TicketService::class);
		$this->orders = $this->createMock(OrderMapper::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->ctrl = new ScanController('bookingcheck', $this->request,
			$this->tickets, $this->orders, $this->session);
	}

	private function param(array $map): void
	{
		$this->request->method('getParam')->willReturnCallback(
			fn (string $k, $d = null) => $map[$k] ?? $d);
	}

	public function testLookupIsReadOnlyAndHonoursCodeParam(): void
	{
		$this->param(['code' => 'BCK1:9:' . str_repeat('a', 40), 'qr' => '']);
		$t = new Ticket();
		$t->setId(9);
		$t->setAttendeeName('Alice');
		$this->tickets->method('resolveQrToken')->willReturn($t);
		// a lookup that mutates = the original dead-workflow bug
		$this->tickets->expects(self::never())->method('checkInByToken');
		$resp = $this->ctrl->lookup();
		self::assertSame(200, $resp->getStatus());
		$d = $resp->getData()['data'];
		self::assertSame(9, $d['ticket_id']);
		self::assertSame('Alice', $d['attendee_name']);
		self::assertFalse($d['checked_in']);
	}

	public function testLookupPrefersCodeOverLegacyQr(): void
	{
		// when both params arrive the scan form's `code` wins over a stale
		// legacy `qr` — a precedence swap resolves the wrong ticket
		$this->param(['code' => 'BCK1:9:' . str_repeat('a', 40),
			'qr' => 'BCK1:8:' . str_repeat('b', 40)]);
		$t = new Ticket();
		$t->setId(9);
		$t->setAttendeeName('Alice');
		$this->tickets->method('resolveQrToken')->willReturnCallback(
			fn (string $tok) => $tok === str_repeat('a', 40) ? $t : null);
		$d = $this->ctrl->lookup()->getData()['data'];
		self::assertSame(9, $d['ticket_id']);
	}

	public function testLookupOrderRefReturnsTicketList(): void
	{
		$this->param(['code' => 'bck-2026-ABCDEFGH', 'qr' => '']);
		$o = new Order();
		$o->setId(5);
		$o->setRef('BCK-2026-ABCDEFGH');
		$o->setStatus('paid');
		$this->orders->method('findByRef')->with('BCK-2026-ABCDEFGH')->willReturn($o);
		$this->tickets->method('liveTicketsForOrder')->with(5)->willReturn([
			['id' => 9, 'seat_no' => 1, 'attendee_name' => 'Alice', 'checked_in_at' => null],
			['id' => 10, 'seat_no' => 2, 'attendee_name' => 'Bob', 'checked_in_at' => 'x'],
		]);
		$this->tickets->expects(self::never())->method('checkInByToken');
		$d = $this->ctrl->lookup()->getData()['data'];
		self::assertSame('BCK-2026-ABCDEFGH', $d['order_ref']);
		self::assertCount(2, $d['tickets']);
	}

	public function testLookupUnpaidOrderIsNotFound(): void
	{
		$this->param(['code' => 'BCK-2026-ABCDEFGH']);
		$o = new Order();
		$o->setId(5);
		$o->setStatus('pending'); // unpaid — uniform 404, no existence oracle
		$this->orders->method('findByRef')->willReturn($o);
		self::assertSame(404, $this->ctrl->lookup()->getStatus());
	}

	public function testLookupRevokedTicketIs409(): void
	{
		$this->param(['code' => 'BCK1:9:' . str_repeat('a', 40)]);
		$t = new Ticket();
		$t->setId(9);
		$t->setRevokedAt(new \DateTimeImmutable());
		$this->tickets->method('resolveQrToken')->willReturn($t);
		self::assertSame(409, $this->ctrl->lookup()->getStatus());
	}

	public function testLookupGarbageIs400AndNeverMutates(): void
	{
		$this->param(['code' => 'hello world']);
		$this->tickets->expects(self::never())->method('resolveQrToken');
		$this->tickets->expects(self::never())->method('checkInByToken');
		self::assertSame(400, $this->ctrl->lookup()->getStatus());
	}
}

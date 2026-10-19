<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit\Controller;

use OCA\BookingCheck\Controller\CapiController;
use OCA\BookingCheck\Db\CompanionToken;
use OCA\BookingCheck\Db\Ticket;
use OCA\BookingCheck\Service\TicketService;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/** Companion scope: offering_ids on the device token is the tenant
 *  boundary — a door tablet must never reach another seller's data. */
final class CapiControllerTest extends TestCase
{
	private IRequest&MockObject $request;
	private TicketService&MockObject $tickets;
	private IDBConnection&MockObject $db;
	private CapiController $ctrl;

	protected function setUp(): void
	{
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->tickets = $this->createMock(TicketService::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->ctrl = new CapiController('bookingcheck', $this->request, $this->tickets, $this->db);
	}

	private function device(?string $offeringIds, string $role = 'door'): CompanionToken
	{
		$d = new CompanionToken();
		$d->setRole($role);
		$d->setOfferingIds($offeringIds);
		$d->setLabel('test-device');
		return $d;
	}

	public function testCheckInForeignScopeIsNotFoundAndNeverMutates(): void
	{
		$this->ctrl->companionDevice = $this->device('[1,2]');
		$this->request->method('getParam')->willReturnCallback(
			fn (string $k, $d = null) => $k === 'qr' ? 'BCK1:9:' . str_repeat('a', 40) : $d);
		$t = new Ticket();
		$t->setId(9);
		$this->tickets->method('resolveQrToken')->willReturn($t);
		$this->tickets->method('offeringIdForTicket')->willReturn(77); // foreign offering
		$this->tickets->expects(self::never())->method('checkInByToken');
		self::assertSame(404, $this->ctrl->checkIn()->getStatus());
	}

	public function testMalformedScopeGrantsNothing(): void
	{
		$this->ctrl->companionDevice = $this->device('{broken');
		$this->request->method('getParam')->willReturnCallback(
			fn (string $k, $d = null) => $k === 'qr' ? 'BCK1:9:' . str_repeat('a', 40) : $d);
		$t = new Ticket();
		$this->tickets->method('resolveQrToken')->willReturn($t);
		$this->tickets->method('offeringIdForTicket')->willReturn(1);
		$this->tickets->expects(self::never())->method('checkInByToken');
		self::assertSame(404, $this->ctrl->checkIn()->getStatus());
	}

	public function testDoorRoleCannotUndoCheckIn(): void
	{
		$this->ctrl->companionDevice = $this->device(null, 'door');
		$this->request->method('getParam')->willReturnCallback(
			fn (string $k, $d = null) => $k === 'ticket_id' ? '9' : $d);
		$this->tickets->expects(self::never())->method('undoCheckIn');
		self::assertSame(403, $this->ctrl->undoCheckIn()->getStatus());
	}

	public function testDoorRoleCannotReadStats(): void
	{
		$this->ctrl->companionDevice = $this->device(null, 'door');
		self::assertSame(403, $this->ctrl->stats()->getStatus());
	}
}

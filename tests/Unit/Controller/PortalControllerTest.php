<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit\Controller;

use OCA\BookingCheck\Controller\PortalController;
use OCA\BookingCheck\Db\Offering;
use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Db\Order;
use OCA\BookingCheck\Db\OrderMapper;
use OCA\BookingCheck\Db\Ticket;
use OCA\BookingCheck\Db\TicketMapper;
use OCA\BookingCheck\Exception\NotFoundException;
use OCA\BookingCheck\Service\EmailService;
use OCA\BookingCheck\Service\EntitlementService;
use OCA\BookingCheck\Service\OtpService;
use OCA\BookingCheck\Service\PortalSessionService;
use OCA\BookingCheck\Service\SettingsService;
use OCA\BookingCheck\Service\TicketService;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * Portal order page — least-PII contract: buyer sees the whole order,
 * an attendee-scoped email sees ONLY its own tickets, and an unentitled
 * id is indistinguishable from a missing one.
 */
final class PortalControllerTest extends TestCase
{
	private IRequest&MockObject $request;
	private PortalSessionService&MockObject $sessions;
	private EntitlementService&MockObject $entitlement;
	private OrderMapper&MockObject $orders;
	private OfferingMapper&MockObject $offerings;
	private TicketMapper&MockObject $tickets;
	private TicketService&MockObject $ticketService;
	private PortalController $ctrl;

	protected function setUp(): void
	{
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->sessions = $this->createMock(PortalSessionService::class);
		$this->entitlement = $this->createMock(EntitlementService::class);
		$this->orders = $this->createMock(OrderMapper::class);
		$this->offerings = $this->createMock(OfferingMapper::class);
		$this->tickets = $this->createMock(TicketMapper::class);
		$this->ticketService = $this->createMock(TicketService::class);
		$this->ctrl = new PortalController('bookingcheck', $this->request,
			$this->createMock(OtpService::class), $this->sessions,
			$this->entitlement, $this->createMock(EmailService::class),
			$this->createMock(SettingsService::class),
			$this->createMock(IURLGenerator::class),
			$this->orders, $this->offerings, $this->tickets, $this->ticketService);
	}

	private function sessionAs(string $email): void
	{
		$this->request->method('getCookie')->willReturn('portal-cookie');
		$this->sessions->method('resolve')->with('portal-cookie')->willReturn($email);
	}

	private function orderFixture(): Order
	{
		$o = new Order();
		$o->setId(5);
		$o->setRef('BCK-2026-AAAA1111');
		$o->setStatus('paid');
		$o->setOfferingId(7);
		return $o;
	}

	private function ticketFixture(int $id, string $email, string $name = 'Guest'): Ticket
	{
		$t = new Ticket();
		$t->setId($id);
		$t->setAttendeeName($name);
		$t->setAttendeeEmail($email);
		return $t;
	}

	public function testOrderWithoutSessionIsUniform404(): void
	{
		$this->request->method('getCookie')->willReturn(null);
		$this->expectException(NotFoundException::class);
		$this->ctrl->order(5);
	}

	public function testOrderUnentitledEmailIsUniform404(): void
	{
		$this->sessionAs('stranger@x.test');
		$this->entitlement->method('orderScope')->willReturn(null);
		$this->orders->method('find')->willReturn($this->orderFixture());
		$this->expectException(NotFoundException::class);
		$this->ctrl->order(5);
	}

	public function testOrderBuyerScopeSeesAllTickets(): void
	{
		$this->sessionAs('buyer@x.test');
		$this->entitlement->method('orderScope')->willReturn('buyer');
		$this->orders->method('find')->willReturn($this->orderFixture());
		$this->offerings->method('find')->willReturn(new Offering());
		$this->tickets->method('findForOrder')->with(5)->willReturn([
			$this->ticketFixture(1, 'a@x.test'),
			$this->ticketFixture(2, 'b@x.test'),
		]);
		$this->ticketService->method('qrSvg')->willReturn('<svg/>');
		$this->entitlement->method('entitledMaterials')->willReturn([
			['id' => 9, 'offering_id' => 7, 'title' => 'Script'],
			['id' => 10, 'offering_id' => 8, 'title' => 'Other offering'],
		]);
		$resp = $this->ctrl->order(5);
		$params = $resp->getParams();
		self::assertCount(2, $params['tickets']);
		// only THIS offering's materials reach the page
		self::assertCount(1, $params['materials']);
		self::assertSame(9, $params['materials'][0]['id']);
		self::assertSame('buyer', $params['scope']);
	}

	public function testOrderAttendeeScopeSeesOnlyOwnTickets(): void
	{
		$this->sessionAs('b@x.test');
		$this->entitlement->method('orderScope')->willReturn('attendee');
		$this->orders->method('find')->willReturn($this->orderFixture());
		$this->offerings->method('find')->willReturn(new Offering());
		$this->tickets->method('findForOrder')->with(5)->willReturn([
			$this->ticketFixture(1, 'a@x.test'),           // other attendee
			$this->ticketFixture(2, 'B@X.TEST', 'Bob'),    // case-folded match
			$this->ticketFixture(3, 'b@x.test', 'Bob'),    // own second ticket
		]);
		$this->ticketService->method('qrSvg')->willReturn('<svg/>');
		$this->entitlement->method('entitledMaterials')->willReturn([]);
		$params = $this->ctrl->order(5)->getParams();
		self::assertCount(2, $params['tickets']);
		self::assertSame('attendee', $params['scope']);
	}
}

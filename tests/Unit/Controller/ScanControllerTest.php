<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit\Controller;

use OCA\BookingCheck\Controller\ScanController;
use OCA\BookingCheck\Db\CompanionToken;
use OCA\BookingCheck\Db\Order;
use OCA\BookingCheck\Db\OrderMapper;
use OCA\BookingCheck\Db\Ticket;
use OCA\BookingCheck\Exception\UnauthorizedException;
use OCA\BookingCheck\Db\CompanionTokenMapper;
use OCA\BookingCheck\Service\DoorSessionService;
use OCA\BookingCheck\Service\TicketService;
use OCA\BookingCheck\Service\TokenService;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCA\BookingCheck\Service\AccessControlService;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/** Door lookup is a PREVIEW: it resolves codes but must never check in.
 *  Contract: `code` param (QR payload BCK1:.. or order ref BCK-YYYY-XXXXXXXX),
 *  response keys ticket_id / attendee_name / checked_in / qr / tickets.
 *
 *  Access: BookingCheck admin OR a live door session — anonymous callers
 *  get a uniform refusal, and door sessions stay scoped to the token's
 *  offering list. */
final class ScanControllerTest extends TestCase
{
	private IRequest&MockObject $request;
	private TicketService&MockObject $tickets;
	private OrderMapper&MockObject $orders;
	private IUserSession&MockObject $session;
	private AccessControlService&MockObject $accessControl;
	private ISession&MockObject $phpSession;
	private CompanionTokenMapper&MockObject $devices;
	private DoorSessionService $doors;
	private ScanController $ctrl;
	private int $scopeOfferingId = 7;

	protected function setUp(): void
	{
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->tickets = $this->createMock(TicketService::class);
		$this->orders = $this->createMock(OrderMapper::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->accessControl = $this->createMock(AccessControlService::class);
		// real DoorSessionService — it's final; the session store + device
		// mapper are the stubbed seams
		$this->phpSession = $this->createMock(ISession::class);
		$this->devices = $this->createMock(CompanionTokenMapper::class);
		$this->doors = new DoorSessionService($this->phpSession, $this->devices,
			$this->createMock(TokenService::class));

		// doorScopeOk() resolves ticket → line's target → owning offering
		// via WaitlistService::offeringForTarget; the stub returns
		// $this->scopeOfferingId — scopeAllows decides.
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('x=y');
		$result = $this->createMock(\OCP\DB\IResult::class);
		$result->method('fetch')->willReturn(
			['target_type' => 'event', 'target_id' => 55]);
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'innerJoin', 'where', 'andWhere'] as $m) {
			$qb->method($m)->willReturnSelf();
		}
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn(':p');
		$qb->method('executeQuery')->willReturn($result);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$waitlist = $this->createMock(\OCA\BookingCheck\Service\WaitlistService::class);
		$waitlist->method('offeringForTarget')
			->willReturnCallback(fn () => $this->scopeOfferingId);

		$this->ctrl = new ScanController('bookingcheck', $this->request,
			$this->tickets, $this->orders, $this->session, $this->accessControl,
			$this->doors, $db, $this->createMock(TokenService::class), $waitlist);
	}

	private function param(array $map): void
	{
		$this->request->method('getParam')->willReturnCallback(
			fn (string $k, $d = null) => $map[$k] ?? $d);
	}

	/** Staff principal: logged-in NC user past the access door. */
	private function asAdmin(): void
	{
		$u = $this->createMock(IUser::class);
		$u->method('getUID')->willReturn('seller');
		$this->session->method('getUser')->willReturn($u);
		$this->accessControl->method('canUseApp')->willReturnCallback(
			fn (string $uid) => $uid === 'seller');
		$this->phpSession->method('get')->willReturn(null);
	}

	/** Door principal: no NC user, a live device session. */
	private function asDoor(string $scopeJson = '[7]'): CompanionToken
	{
		$this->session->method('getUser')->willReturn(null);
		$device = new CompanionToken();
		$device->setId(3);
		$device->setRole('door');
		$device->setLabel('Gate tablet');
		$device->setOfferingIds($scopeJson);
		$this->phpSession->method('get')
			->with(DoorSessionService::SESSION_KEY)->willReturn(3);
		$this->devices->method('find')->with(3)->willReturn($device);
		return $device;
	}

	public function testAnonymousLookupIsRefused(): void
	{
		$this->param(['code' => 'BCK1:9:' . str_repeat('a', 40)]);
		$this->phpSession->method('get')->willReturn(null); // no door session
		$this->expectException(UnauthorizedException::class);
		$this->ctrl->lookup();
	}

	public function testAnonymousCheckInIsRefused(): void
	{
		$this->param(['code' => 'BCK1:9:' . str_repeat('a', 40)]);
		$this->phpSession->method('get')->willReturn(null);
		$this->expectException(UnauthorizedException::class);
		$this->ctrl->checkIn();
	}

	public function testOpenSessionOk(): void
	{
		$this->param(['token' => 'device-token']);
		$device = new CompanionToken();
		$device->setId(3);
		$device->setRole('door');
		$this->devices->method('findByTokenHash')->willReturn($device);
		$this->devices->expects(self::once())->method('touch')->with(3);
		$this->phpSession->expects(self::once())->method('set')
			->with(DoorSessionService::SESSION_KEY, 3);
		$d = $this->ctrl->openSession()->getData()['data'];
		self::assertTrue($d['door']);
	}

	public function testOpenSessionBadTokenIsUniform401(): void
	{
		$this->param(['token' => 'wrong']);
		$this->devices->method('findByTokenHash')->willReturn(null);
		$this->phpSession->expects(self::never())->method('set');
		$resp = $this->ctrl->openSession();
		self::assertSame(401, $resp->getStatus());
		self::assertSame('UNAUTHORIZED', $resp->getData()['error']);
	}

	public function testCloseSessionClears(): void
	{
		$this->phpSession->expects(self::once())->method('remove')
			->with(DoorSessionService::SESSION_KEY);
		$d = $this->ctrl->closeSession()->getData()['data'];
		self::assertFalse($d['door']);
	}

	public function testLookupIsReadOnlyAndHonoursCodeParam(): void
	{
		$this->asAdmin();
		$this->param(['code' => 'BCK1:9:' . str_repeat('a', 40), 'qr' => '']);
		$t = new Ticket();
		$t->setId(9);
		$t->setAttendeeName('Alice');
		$this->tickets->method('resolveQrToken')->willReturn($t);
		$this->tickets->method('qrPayload')->willReturn('BCK1:9:' . str_repeat('a', 40));
		// a lookup that mutates = the original dead-workflow bug
		$this->tickets->expects(self::never())->method('checkInByToken');
		$resp = $this->ctrl->lookup();
		self::assertSame(200, $resp->getStatus());
		$d = $resp->getData()['data'];
		self::assertSame(9, $d['ticket_id']);
		self::assertSame('Alice', $d['attendee_name']);
		self::assertFalse($d['checked_in']);
		self::assertSame('BCK1:9:' . str_repeat('a', 40), $d['qr']);
	}

	public function testLookupPrefersCodeOverLegacyQr(): void
	{
		$this->asAdmin();
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
		$this->asAdmin();
		$this->param(['code' => 'bck-2026-ABCDEFGH', 'qr' => '']);
		$o = new Order();
		$o->setId(5);
		$o->setRef('BCK-2026-ABCDEFGH');
		$o->setStatus('paid');
		$this->orders->method('findByRef')->with('BCK-2026-ABCDEFGH')->willReturn($o);
		$this->tickets->method('liveTicketsForOrder')->with(5)->willReturn([
			['id' => 9, 'seat_no' => 1, 'attendee_name' => 'Alice', 'checked_in_at' => null,
				'qr' => 'BCK1:9:aaaa'],
			['id' => 10, 'seat_no' => 2, 'attendee_name' => 'Bob', 'checked_in_at' => 'x',
				'qr' => 'BCK1:10:bbbb'],
		]);
		$this->tickets->expects(self::never())->method('checkInByToken');
		$d = $this->ctrl->lookup()->getData()['data'];
		self::assertSame('BCK-2026-ABCDEFGH', $d['order_ref']);
		self::assertCount(2, $d['tickets']);
	}

	public function testLookupUnpaidOrderIsNotFound(): void
	{
		$this->asAdmin();
		$this->param(['code' => 'BCK-2026-ABCDEFGH']);
		$o = new Order();
		$o->setId(5);
		$o->setStatus('pending'); // unpaid — uniform 404, no existence oracle
		$this->orders->method('findByRef')->willReturn($o);
		self::assertSame(404, $this->ctrl->lookup()->getStatus());
	}

	public function testLookupRevokedTicketIs409(): void
	{
		$this->asAdmin();
		$this->param(['code' => 'BCK1:9:' . str_repeat('a', 40)]);
		$t = new Ticket();
		$t->setId(9);
		$t->setRevokedAt(new \DateTimeImmutable());
		$this->tickets->method('resolveQrToken')->willReturn($t);
		self::assertSame(409, $this->ctrl->lookup()->getStatus());
	}

	public function testLookupGarbageIs400AndNeverMutates(): void
	{
		$this->asAdmin();
		$this->param(['code' => 'hello world']);
		$this->tickets->expects(self::never())->method('resolveQrToken');
		$this->tickets->expects(self::never())->method('checkInByToken');
		self::assertSame(400, $this->ctrl->lookup()->getStatus());
	}

	public function testDoorLookupInScopeSucceeds(): void
	{
		$this->asDoor('[7]'); // device scoped to offering 7; QB says 7
		$this->param(['code' => 'BCK1:9:' . str_repeat('a', 40)]);
		$t = new Ticket();
		$t->setId(9);
		$t->setAttendeeName('Alice');
		$this->tickets->method('resolveQrToken')->willReturn($t);
		self::assertSame(200, $this->ctrl->lookup()->getStatus());
	}

	public function testDoorLookupOutOfScopeIsUniform404(): void
	{
		$this->asDoor('[1,2]'); // ticket's offering is 7 — out of scope
		$this->param(['code' => 'BCK1:9:' . str_repeat('a', 40)]);
		$this->tickets->expects(self::never())->method('resolveQrToken');
		$resp = $this->ctrl->lookup();
		self::assertSame(404, $resp->getStatus());
		self::assertSame('NOT_FOUND', $resp->getData()['error']);
	}

	public function testDoorCheckInOutOfScopeIsUniform404(): void
	{
		$this->asDoor('[1,2]'); // ticket's offering is 7 — out of scope
		$this->param(['code' => 'BCK1:9:' . str_repeat('a', 40)]);
		$this->tickets->expects(self::never())->method('checkInByToken');
		$resp = $this->ctrl->checkIn();
		self::assertSame(404, $resp->getStatus());
	}

	public function testDoorCheckInUsesDeviceActor(): void
	{
		$this->asDoor('[7]');
		$this->param(['code' => 'BCK1:9:' . str_repeat('a', 40)]);
		$t = new Ticket();
		$t->setId(9);
		$this->tickets->expects(self::once())->method('checkInByToken')
			->with(str_repeat('a', 40), 'door:3')
			->willReturn(['ok' => true, 'already' => false, 'ticket' => $t]);
		$d = $this->ctrl->checkIn()->getData()['data'];
		self::assertTrue($d['checked_in']);
	}

	public function testDoorOrderRefOutOfScopeIsUniform404(): void
	{
		$this->asDoor('[1]');
		$this->param(['code' => 'BCK-2026-ABCDEFGH']);
		$o = new Order();
		$o->setId(5);
		$o->setStatus('paid');
		$o->setOfferingId(7);
		$this->orders->method('findByRef')->willReturn($o);
		// tickets resolve to offering 7 via their line's target — not the
		// door's scope, so the whole order is invisible
		$this->tickets->method('liveTicketsForOrder')->willReturn([
			['id' => 9, 'seat_no' => 1, 'attendee_name' => 'A',
				'checked_in_at' => null, 'qr' => 'x', 'offering_id' => 7],
		]);
		self::assertSame(404, $this->ctrl->lookup()->getStatus());
	}

	public function testDoorOrderRefFiltersCartToInScopeTickets(): void
	{
		$this->asDoor('[8]'); // cart: lead offering 7 + line offering 8
		$this->param(['code' => 'BCK-2026-ABCDEFGH']);
		$o = new Order();
		$o->setId(5);
		$o->setStatus('paid');
		$o->setOfferingId(7); // lead — NOT this door's offering
		$this->orders->method('findByRef')->willReturn($o);
		$this->tickets->method('liveTicketsForOrder')->willReturn([
			['id' => 9, 'seat_no' => 1, 'attendee_name' => 'A',
				'checked_in_at' => null, 'qr' => 'qa', 'offering_id' => 7],
			['id' => 10, 'seat_no' => 2, 'attendee_name' => 'B',
				'checked_in_at' => null, 'qr' => 'qb', 'offering_id' => 8],
		]);
		$d = $this->ctrl->lookup()->getData()['data'];
		self::assertCount(1, $d['tickets'],
			'a cart door sees only its own offering\'s tickets');
		self::assertSame(10, $d['tickets'][0]['id']);
		self::assertArrayNotHasKey('offering_id', $d['tickets'][0],
			'internal scope key must not leak into the payload');
	}
}

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

	// ---- api role = read-only integration (label ↔ enforcement) ----

	/** The settings picker labels `api` as "read-only integration" — the
	 *  manifest must never advertise a mutation capability and every
	 *  mutating endpoint must refuse the role (bkc api-role intake). */
	public function testApiManifestAdvertisesReadsOnly(): void
	{
		$this->ctrl->companionDevice = $this->device(null, 'api');
		$caps = $this->ctrl->manifest()->getData()['data']['capabilities'];
		self::assertSame(['events', 'roster', 'stats', 'push'], $caps);
	}

	public function testManagerManifestIncludesOfferingWrite(): void
	{
		$this->ctrl->companionDevice = $this->device(null, 'manager');
		$caps = $this->ctrl->manifest()->getData()['data']['capabilities'];
		self::assertContains('offerings.write', $caps);
		self::assertContains('orders', $caps);
		self::assertContains('refund', $caps);
	}

	public function testApiDeviceCannotCheckIn(): void
	{
		$this->ctrl->companionDevice = $this->device(null, 'api');
		$this->request->method('getParam')->willReturnCallback(
			fn (string $k, $d = null) => $k === 'qr' ? 'BCK1:9:' . str_repeat('a', 40) : $d);
		$this->tickets->expects(self::never())->method('checkInByToken');
		self::assertSame(403, $this->ctrl->checkIn()->getStatus());
	}

	public function testApiDeviceCannotAdmitPass(): void
	{
		$this->ctrl->companionDevice = $this->device(null, 'api');
		$this->request->method('getParam')->willReturnCallback(
			fn (string $k, $d = null) => $k === 'payload' ? 'BCK1P-' . str_repeat('a', 48) : $d);
		self::assertSame(403, $this->ctrl->admitPass()->getStatus());
	}

	public function testApiDeviceCannotSellWalkup(): void
	{
		$this->ctrl->companionDevice = $this->device(null, 'api');
		self::assertSame(403, $this->ctrl->walkup()->getStatus());
	}

	public function testApiDeviceCannotCreateOffering(): void
	{
		$this->ctrl->companionDevice = $this->device(null, 'api');
		self::assertSame(403, $this->ctrl->createOffering()->getStatus());
	}

	public function testDoorDeviceCannotCreateOffering(): void
	{
		$this->ctrl->companionDevice = $this->device(null, 'door');
		self::assertSame(403, $this->ctrl->createOffering()->getStatus());
	}

	/** The read-only api role CAN read stats — reporting is its purpose
	 *  and the manifest has always advertised it. */
	public function testApiDeviceCanReadStats(): void
	{
		$rs = $this->createMock(\OCP\DB\IResult::class);
		$rs->method('fetchAll')->willReturn([]);
		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn(':p');
		$qb->method('createFunction')->willReturn('1');
		foreach (['select', 'selectAlias', 'from', 'innerJoin', 'where',
			'andWhere', 'orderBy', 'setMaxResults'] as $m) {
			$qb->method($m)->willReturnSelf();
		}
		$qb->method('executeQuery')->willReturn($rs);
		$this->db->method('getQueryBuilder')->willReturn($qb);
		$this->ctrl->companionDevice = $this->device(null, 'api');
		$res = $this->ctrl->stats();
		self::assertSame(200, $res->getStatus());
		self::assertSame([], $res->getData()['data']['stats']);
	}

	public function testApiDeviceCannotUndoOrSeeOrders(): void
	{
		$this->ctrl->companionDevice = $this->device(null, 'api');
		$this->request->method('getParam')->willReturnCallback(
			fn (string $k, $d = null) => $k === 'ticket_id' ? '9' : $d);
		self::assertSame(403, $this->ctrl->undoCheckIn()->getStatus());
		$ctrl = $this->mgrCtrl();
		$ctrl->companionDevice = $this->device(null, 'api');
		self::assertSame(403, $ctrl->orders()->getStatus());
		self::assertSame(403, $ctrl->orderRefund(9)->getStatus());
	}

	/** NH-04 write API — a manager device can mint a quick offering. */
	public function testManagerCanCreateOffering(): void
	{
		$orderSvc = $this->createMock(\OCA\BookingCheck\Service\OrderService::class);
		$this->request->method('getParam')->willReturnCallback(
			fn (string $k, $d = null) => match ($k) {
				'title' => 'API event',
				'capacity' => '25',
				'starts_at' => '2099-01-01T10:00:00+00:00',
				default => $d,
			});
		$orderSvc->expects(self::once())->method('createQuickOffering')
			->with('API event', 0, 25, self::anything(), 60, 'device:test-device')
			->willReturn([5, 9]);
		$ctrl = new CapiController('bookingcheck', $this->request, $this->tickets,
			$this->db, null, $orderSvc);
		$ctrl->companionDevice = $this->device(null, 'manager');
		$res = $ctrl->createOffering();
		self::assertSame(201, $res->getStatus());
		self::assertSame(5, $res->getData()['data']['offering_id']);
		self::assertSame(9, $res->getData()['data']['event_id']);
	}

	// ---- manager orders + quick refund (NH-04) ----------------------

	private \OCA\BookingCheck\Service\OrderService&MockObject $orderSvc;
	private \OCA\BookingCheck\Db\PaymentMapper&MockObject $payments;
	private \OCA\BookingCheck\Psp\PspManager&MockObject $psp;

	/**
	 * Controller wired with a REAL RefundService over mocked
	 * collaborators — the manager endpoints must be proven against the
	 * actual money path, not a stand-in that can drift from it.
	 */
	private function mgrCtrl(?\OCA\BookingCheck\Db\OrderMapper $orders = null): CapiController
	{
		$this->orderSvc = $this->createMock(\OCA\BookingCheck\Service\OrderService::class);
		$this->payments = $this->createMock(\OCA\BookingCheck\Db\PaymentMapper::class);
		$this->psp = $this->createMock(\OCA\BookingCheck\Psp\PspManager::class);
		$refunds = new \OCA\BookingCheck\Service\RefundService(
			$this->orderSvc, $this->payments, $this->psp);
		return new CapiController(
			'bookingcheck', $this->request, $this->tickets, $this->db,
			null, null, null, $refunds, $orders);
	}

	private function paymentFixture(int $amountCents = 5000, string $refundRef = ''): \OCA\BookingCheck\Db\Payment
	{
		$p = new \OCA\BookingCheck\Db\Payment();
		$p->setId(7);
		$p->setAmountCents($amountCents);
		$p->setPspRef('psp-live-1');
		$p->setProvider('test');
		$p->setRefundRef($refundRef);
		return $p;
	}

	private function orderFixture(int $offeringId): \OCA\BookingCheck\Db\Order
	{
		$o = new \OCA\BookingCheck\Db\Order();
		$o->setId(9);
		$o->setRef('BCK-9');
		$o->setOfferingId($offeringId);
		$o->setBuyerName('Ada');
		$o->setBuyerEmail('ada@example.org');
		$o->setStatus('paid');
		$o->setAmountCents(5000);
		$o->setCurrency('EUR');
		$o->setIsTest(0);
		$o->setCreatedAt(new \DateTimeImmutable('2026-10-01T10:00:00+00:00'));
		return $o;
	}

	public function testDoorRoleCannotListOrders(): void
	{
		$orders = $this->createMock(\OCA\BookingCheck\Db\OrderMapper::class);
		$orders->expects(self::never())->method('findRecentScoped');
		$ctrl = $this->mgrCtrl($orders);
		$ctrl->companionDevice = $this->device(null, 'door');
		self::assertSame(403, $ctrl->orders()->getStatus());
	}

	public function testDoorRoleCannotRefund(): void
	{
		$orders = $this->createMock(\OCA\BookingCheck\Db\OrderMapper::class);
		$orders->expects(self::never())->method('find');
		$ctrl = $this->mgrCtrl($orders);
		$ctrl->companionDevice = $this->device(null, 'door');
		self::assertSame(403, $ctrl->orderRefund(9)->getStatus());
	}

	public function testManagerOrdersListPassesScopeAndClampsLimit(): void
	{
		$orders = $this->createMock(\OCA\BookingCheck\Db\OrderMapper::class);
		$this->request->method('getParam')->willReturnCallback(
			fn (string $k, $d = null) => $k === 'limit' ? '9999' : $d);
		$orders->expects(self::once())->method('findRecentScoped')
			->with([1, 2], 100)
			->willReturn([$this->orderFixture(1)]);
		$ctrl = $this->mgrCtrl($orders);
		$ctrl->companionDevice = $this->device('[1,2]', 'manager');
		$res = $ctrl->orders();
		self::assertSame(200, $res->getStatus());
		$payload = $res->getData()['data']['orders'];
		self::assertCount(1, $payload);
		self::assertSame('BCK-9', $payload[0]['ref']);
		// trimmed payload — internals must not ride the companion wire
		self::assertArrayNotHasKey('staff_note', $payload[0]);
	}

	public function testManagerMalformedScopeSeesNoOrders(): void
	{
		$orders = $this->createMock(\OCA\BookingCheck\Db\OrderMapper::class);
		$orders->expects(self::once())->method('findRecentScoped')
			->with([], self::anything())->willReturn([]);
		$ctrl = $this->mgrCtrl($orders);
		$ctrl->companionDevice = $this->device('{broken', 'manager');
		$res = $ctrl->orders();
		self::assertSame(200, $res->getStatus());
		self::assertSame([], $res->getData()['data']['orders']);
	}

	public function testManagerOrderForeignScopeIsNotFound(): void
	{
		$orders = $this->createMock(\OCA\BookingCheck\Db\OrderMapper::class);
		$orders->method('find')->with(9)->willReturn($this->orderFixture(77));
		$ctrl = $this->mgrCtrl($orders);
		$ctrl->companionDevice = $this->device('[1,2]', 'manager');
		self::assertSame(404, $ctrl->order(9)->getStatus());
	}

	public function testManagerRefundForeignOrderIs404AndNeverMovesMoney(): void
	{
		$orders = $this->createMock(\OCA\BookingCheck\Db\OrderMapper::class);
		$orders->method('find')->willReturn($this->orderFixture(77));
		$ctrl = $this->mgrCtrl($orders);
		$ctrl->companionDevice = $this->device('[1,2]', 'manager');
		$this->payments->expects(self::never())->method('claimRefund');
		$this->payments->expects(self::never())->method('findPaidByOrder');
		$this->orderSvc->expects(self::never())->method('transitionToRefunded');
		self::assertSame(404, $ctrl->orderRefund(9)->getStatus());
		self::assertSame(404, $ctrl->orderRefundQuote(9)->getStatus());
	}

	public function testManagerRefundMovesMoneyWithDeviceActor(): void
	{
		$orders = $this->createMock(\OCA\BookingCheck\Db\OrderMapper::class);
		$orders->method('find')->with(9)->willReturn($this->orderFixture(1));
		$this->request->method('getParam')->willReturnCallback(
			fn (string $k, $d = null) => $k === 'amount_cents' ? '5000' : $d);
		$provider = $this->createMock(\OCA\BookingCheck\Psp\PaymentProviderInterface::class);
		$provider->method('refund')->willReturn(
			new \OCA\BookingCheck\Psp\RefundResult(true, 'ref-1'));
		$ctrl = $this->mgrCtrl($orders);
		$this->payments->method('findPaidByOrder')->willReturn($this->paymentFixture(5000));
		$this->payments->method('claimRefund')->willReturn(true);
		$this->psp->method('byName')->willReturn($provider);
		$this->orderSvc->expects(self::once())->method('transitionToRefunded')
			->with(9, 'device:test-device', ['amount' => 5000], 5000);
		$ctrl->companionDevice = $this->device('[1,2]', 'manager');
		$res = $ctrl->orderRefund(9);
		self::assertSame(200, $res->getStatus());
		self::assertSame('ref-1', $res->getData()['data']['refund_ref']);
	}

	public function testManagerRefundQuoteReturnsSettledTranche(): void
	{
		$orders = $this->createMock(\OCA\BookingCheck\Db\OrderMapper::class);
		$orders->method('find')->willReturn($this->orderFixture(1));
		$ctrl = $this->mgrCtrl($orders);
		$this->payments->method('findPaidByOrder')->willReturn($this->paymentFixture(2500));
		$ctrl->companionDevice = $this->device('[1,2]', 'manager');
		$res = $ctrl->orderRefundQuote(9);
		self::assertSame(200, $res->getStatus());
		self::assertSame(2500, $res->getData()['data']['amount_cents']);
	}

	public function testManagerRefundMapsValidationConflictAndPspErrors(): void
	{
		$this->request->method('getParam')->willReturnCallback(
			fn (string $k, $d = null) => $k === 'amount_cents' ? '5000' : $d);
		$orders = $this->createMock(\OCA\BookingCheck\Db\OrderMapper::class);

		// 400 — a non-refundable status refuses before money logic
		$pending = $this->orderFixture(1);
		$pending->setStatus('pending_payment');
		$orders->method('find')->willReturn($pending);
		$ctrl = $this->mgrCtrl($orders);
		$ctrl->companionDevice = $this->device(null, 'manager');
		self::assertSame(400, $ctrl->orderRefund(9)->getStatus());
		self::assertSame(400, $ctrl->orderRefundQuote(9)->getStatus());

		// 409 — claim lost to an in-flight refund
		$orders = $this->createMock(\OCA\BookingCheck\Db\OrderMapper::class);
		$orders->method('find')->willReturn($this->orderFixture(1));
		$ctrl = $this->mgrCtrl($orders);
		$this->payments->method('findPaidByOrder')->willReturn($this->paymentFixture());
		$this->payments->method('claimRefund')->willReturn(false);
		$this->payments->method('find')->willReturn($this->paymentFixture(5000, 'pending:abc'));
		$ctrl->companionDevice = $this->device(null, 'manager');
		self::assertSame(409, $ctrl->orderRefund(9)->getStatus());

		// 502 — the PSP refused; the claim must be released so a retry works
		$orders = $this->createMock(\OCA\BookingCheck\Db\OrderMapper::class);
		$orders->method('find')->willReturn($this->orderFixture(1));
		$ctrl = $this->mgrCtrl($orders);
		$this->payments->method('findPaidByOrder')->willReturn($this->paymentFixture());
		$this->payments->method('claimRefund')->willReturn(true);
		$this->payments->expects(self::once())->method('releaseRefundClaim');
		$provider = $this->createMock(\OCA\BookingCheck\Psp\PaymentProviderInterface::class);
		$provider->method('refund')->willReturn(
			new \OCA\BookingCheck\Psp\RefundResult(false, '', 'card_declined'));
		$this->psp->method('byName')->willReturn($provider);
		$ctrl->companionDevice = $this->device(null, 'manager');
		self::assertSame(502, $ctrl->orderRefund(9)->getStatus());
	}
}

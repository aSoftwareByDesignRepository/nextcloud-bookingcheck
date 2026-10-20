<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit\Controller;

use OCA\BookingCheck\Controller\SlotController;
use OCA\BookingCheck\Db\EventMapper;
use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Db\SeriesMapper;
use OCA\BookingCheck\Db\Slot;
use OCA\BookingCheck\Db\SlotMapper;
use OCA\BookingCheck\Db\SlotTplMapper;
use OCA\BookingCheck\Exception\ValidationException;
use OCA\BookingCheck\Service\EmailService;
use OCA\BookingCheck\Service\SlotMaterializer;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/** Booked inventory must never be hard-deleted — order lines would
 *  point at a ghost target and the counters could never reconcile. */
final class SlotControllerTest extends TestCase
{
	private SlotMapper&MockObject $slots;
	private EventMapper&MockObject $events;
	private \OCA\BookingCheck\Db\SlotResourceMapper&MockObject $slotRes;
	private EmailService&MockObject $email;
	private SlotController $ctrl;
	/** @var array<string, mixed> request params the IRequest mock serves */
	private array $params = [];
	/** @var list<array<string,mixed>> rows the shared QB fetchAll() serves */
	private array $queryRows = [];

	protected function setUp(): void
	{
		parent::setUp();
		$this->slots = $this->createMock(SlotMapper::class);
		$this->events = $this->createMock(EventMapper::class);
		$this->slotRes = $this->createMock(\OCA\BookingCheck\Db\SlotResourceMapper::class);
		$this->email = $this->createMock(EmailService::class);
		$this->params = [];
		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$expr->method('eq')->willReturn('x=y');
		$expr->method('in')->willReturn('x IN (:p)');
		$expr->method('andX')->willReturn(
			$this->createMock(\OCP\DB\QueryBuilder\ICompositeExpression::class));
		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$qb->method('delete')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('selectDistinct')->willReturnSelf();
		$qb->method('addSelect')->willReturnSelf();
		$qb->method('innerJoin')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn(':p');
		$qb->method('executeStatement')->willReturn(1);
		$result = $this->createMock(\OCP\DB\IResult::class);
		$result->method('fetchAll')->willReturnCallback(fn () => $this->queryRows);
		$result->method('fetch')->willReturn(false);
		$qb->method('executeQuery')->willReturn($result);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $k, $d = null) => $this->params[$k] ?? $d);
		$this->ctrl = new SlotController(
			'bookingcheck',
			$request,
			$this->createMock(OfferingMapper::class),
			$this->events,
			$this->createMock(SlotTplMapper::class),
			$this->slots,
			$this->createMock(SeriesMapper::class),
			$db,
			$this->createMock(SlotMaterializer::class),
			$this->createMock(\OCA\BookingCheck\Db\ResourceMapper::class),
			$this->slotRes,
			$this->email,
		);
	}

	/** Stub find + findForUpdate together — update()/destroy() re-read
	 *  the row under a lock before deciding. */
	private function slotExists(?Slot $s): void
	{
		$this->slotExists($s);
		$this->slots->method('findForUpdate')->willReturn($s);
	}

	private function eventExists(?\OCA\BookingCheck\Db\Event $e): void
	{
		$this->eventExists($e);
		$this->events->method('findForUpdate')->willReturn($e);
	}

	private function poolRow(int $sold, int $held): \OCA\BookingCheck\Db\SlotResource
	{
		$sr = new \OCA\BookingCheck\Db\SlotResource();
		$sr->setSold($sold);
		$sr->setHeld($held);
		return $sr;
	}

	public function testBookedSlotCannotBeDeleted(): void
	{
		$s = new Slot();
		$s->setId(9);
		$s->setSold(2);
		$s->setHeld(0);
		$this->slotExists($s);
		$this->slots->expects(self::never())->method('delete');
		$this->expectException(ValidationException::class);
		$this->ctrl->destroy(9);
	}

	public function testHeldSlotCannotBeDeleted(): void
	{
		$s = new Slot();
		$s->setId(9);
		$s->setSold(0);
		$s->setHeld(1);
		$this->slotExists($s);
		$this->slots->expects(self::never())->method('delete');
		$this->expectException(ValidationException::class);
		$this->ctrl->destroy(9);
	}

	public function testEmptySlotDeletes(): void
	{
		$s = new Slot();
		$s->setId(9);
		$s->setSold(0);
		$s->setHeld(0);
		$this->slotExists($s);
		$this->slotRes->method('lockRowsForSlot')->with(9)->willReturn([$this->poolRow(0, 0)]);
		$this->slots->expects(self::once())->method('delete')->with($s);
		$r = $this->ctrl->destroy(9);
		self::assertTrue($r->getData()['data']['deleted'] ?? false);
	}

	/** Resource-bound slots keep counters on bc_slot_res — a slot row at
	 *  zero can still carry live pool bookings and must not delete. */
	public function testPoolBookedSlotCannotBeDeleted(): void
	{
		$s = new Slot();
		$s->setId(9);
		$s->setSold(0);
		$s->setHeld(0);
		$this->slotExists($s);
		$this->slotRes->method('lockRowsForSlot')->with(9)->willReturn([$this->poolRow(1, 0)]);
		$this->slots->expects(self::never())->method('delete');
		$this->expectException(ValidationException::class);
		$this->ctrl->destroy(9);
	}

	// ---- update() — the route existed; the method must too ----

	private function slotFixture(int $sold, int $held, int $cap = 40): Slot
	{
		$s = new Slot();
		$s->setId(9);
		$s->setSold($sold);
		$s->setHeld($held);
		$s->setCapacity($cap);
		$s->setStartsAt(new \DateTimeImmutable('2026-11-01 10:00:00'));
		$s->setEndsAt(new \DateTimeImmutable('2026-11-01 12:00:00'));
		return $s;
	}

	private function validParams(int $cap = 40): void
	{
		$this->params = [
			'starts_at' => '2026-11-02T10:00',
			'ends_at' => '2026-11-02T12:00',
			'capacity' => (string)$cap,
		];
	}

	public function testUpdateSlotMovesTimesAndPersists(): void
	{
		$this->validParams();
		$s = $this->slotFixture(0, 0);
		$this->slotExists($s);
		$this->slotRes->method('hasRows')->willReturn(false);
		$this->slots->expects(self::once())->method('update')->with($s);
		$r = $this->ctrl->update(9);
		self::assertSame(9, $r->getData()['data']['id'] ?? null);
		self::assertSame('2026-11-02', $s->getStartsAt()->format('Y-m-d'));
	}

	/** A moved occurrence must tell paid buyers — otherwise the seller
	 *  reschedules silently and nobody shows up at the right time. */
	public function testUpdateMovedSlotNotifiesPaidBuyers(): void
	{
		$this->validParams();
		$s = $this->slotFixture(3, 0);
		$this->slotExists($s);
		$this->slotRes->method('hasRows')->willReturn(true);
		$this->queryRows = [['id' => 5, 'ref' => 'BCK-2026-AAAAAA',
			'buyer_email' => 'buyer@x.test', 'buyer_locale' => 'en', 'offering_id' => 1]];
		$this->email->expects(self::atLeastOnce())->method('queue')
			->with('rescheduled');
		$this->ctrl->update(9);
	}

	/** Same-time capacity-only edits don't spam buyers. */
	public function testUpdateUnmovedSlotSendsNoMail(): void
	{
		$this->validParams();
		$this->params['starts_at'] = '2026-11-01T10:00';
		$this->params['ends_at'] = '2026-11-01T12:00';
		$s = $this->slotFixture(3, 0);
		$this->slotExists($s);
		$this->slotRes->method('hasRows')->willReturn(false);
		// a paid buyer exists — if notify ran anyway, mail would go out
		$this->queryRows = [['id' => 5, 'ref' => 'BCK-2026-AAAAAA',
			'buyer_email' => 'buyer@x.test', 'buyer_locale' => 'en', 'offering_id' => 1]];
		$this->email->expects(self::never())->method('queue');
		$this->ctrl->update(9);
	}

	/** Capacity below sold+held silently oversells — fail closed. */
	public function testUpdateCapacityBelowBookedRejected(): void
	{
		$this->validParams(5);
		$s = $this->slotFixture(6, 2);
		$this->slotExists($s);
		$this->slotRes->method('hasRows')->willReturn(false);
		$this->slots->expects(self::never())->method('update');
		$this->expectException(ValidationException::class);
		$this->ctrl->update(9);
	}

	/** Pooled slots: cap lives on bc_slot_res — a row-level change would
	 *  desync the two counter homes (same class as the delete bug). */
	public function testUpdatePooledSlotCapacityChangeRejected(): void
	{
		$this->validParams(99);
		$s = $this->slotFixture(0, 0, 40);
		$this->slotExists($s);
		$this->slotRes->method('hasRows')->willReturn(true);
		$this->slots->expects(self::never())->method('update');
		$this->expectException(ValidationException::class);
		$this->ctrl->update(9);
	}

	public function testUpdatePooledSlotSameCapacityAllowed(): void
	{
		$this->validParams(40);
		$s = $this->slotFixture(0, 0, 40);
		$this->slotExists($s);
		$this->slotRes->method('hasRows')->willReturn(true);
		$this->slots->expects(self::once())->method('update');
		$this->ctrl->update(9);
	}

	public function testUpdateEventPath(): void
	{
		$this->validParams();
		$this->slotExists(null);
		$e = new \OCA\BookingCheck\Db\Event();
		$e->setId(9);
		$e->setSold(0);
		$e->setHeld(0);
		$e->setCapacity(20);
		$e->setStartsAt(new \DateTimeImmutable('2026-11-01 10:00:00'));
		$e->setEndsAt(new \DateTimeImmutable('2026-11-01 12:00:00'));
		$this->eventExists($e);
		$this->events->expects(self::once())->method('update')->with($e);
		$this->ctrl->update(9);
	}

	public function testUpdateUnknownId404(): void
	{
		$this->validParams();
		$this->slotExists(null);
		$this->eventExists(null);
		$this->expectException(\OCA\BookingCheck\Exception\NotFoundException::class);
		$this->ctrl->update(9);
	}

	public function testUpdateEndBeforeStartRejected(): void
	{
		$this->params = [
			'starts_at' => '2026-11-02T12:00',
			'ends_at' => '2026-11-02T10:00',
			'capacity' => '40',
		];
		$this->slots->expects(self::never())->method('update');
		$this->expectException(ValidationException::class);
		$this->ctrl->update(9);
	}
}

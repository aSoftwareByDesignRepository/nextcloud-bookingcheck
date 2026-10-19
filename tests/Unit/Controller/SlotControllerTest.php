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
	private SlotController $ctrl;

	protected function setUp(): void
	{
		parent::setUp();
		$this->slots = $this->createMock(SlotMapper::class);
		$this->events = $this->createMock(EventMapper::class);
		$this->slotRes = $this->createMock(\OCA\BookingCheck\Db\SlotResourceMapper::class);
		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$expr->method('eq')->willReturn('x=y');
		$expr->method('in')->willReturn('x IN (:p)');
		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$qb->method('delete')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn(':p');
		$qb->method('executeStatement')->willReturn(1);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$this->ctrl = new SlotController(
			'bookingcheck',
			$this->createMock(IRequest::class),
			$this->createMock(OfferingMapper::class),
			$this->events,
			$this->createMock(SlotTplMapper::class),
			$this->slots,
			$this->createMock(SeriesMapper::class),
			$db,
			$this->createMock(SlotMaterializer::class),
			$this->createMock(\OCA\BookingCheck\Db\ResourceMapper::class),
			$this->slotRes,
		);
	}

	public function testBookedSlotCannotBeDeleted(): void
	{
		$s = new Slot();
		$s->setId(9);
		$s->setSold(2);
		$s->setHeld(0);
		$this->slots->method('find')->willReturn($s);
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
		$this->slots->method('find')->willReturn($s);
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
		$this->slots->method('find')->willReturn($s);
		$this->slotRes->method('hasBookings')->with(9)->willReturn(false);
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
		$this->slots->method('find')->willReturn($s);
		$this->slotRes->method('hasBookings')->with(9)->willReturn(true);
		$this->slots->expects(self::never())->method('delete');
		$this->expectException(ValidationException::class);
		$this->ctrl->destroy(9);
	}
}

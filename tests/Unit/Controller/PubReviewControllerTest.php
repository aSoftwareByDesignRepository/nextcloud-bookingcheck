<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit\Controller;

use OCA\BookingCheck\Controller\PubReviewController;
use OCA\BookingCheck\Db\Offering;
use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Db\Order;
use OCA\BookingCheck\Db\OrderLine;
use OCA\BookingCheck\Db\OrderLineMapper;
use OCA\BookingCheck\Db\OrderMapper;
use OCA\BookingCheck\Db\ReviewMapper;
use OCA\BookingCheck\Service\TokenService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/** Review writes are capability-gated: bare line_id is enumerable, so
 *  the manage token is the only acceptable proof of purchase. */
final class PubReviewControllerTest extends TestCase
{
	private IRequest&MockObject $request;
	private ReviewMapper&MockObject $reviews;
	private OrderLineMapper&MockObject $lines;
	private OrderMapper&MockObject $orders;
	private OfferingMapper&MockObject $offerings;
	private PubReviewController $ctrl;

	protected function setUp(): void
	{
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->reviews = $this->createMock(ReviewMapper::class);
		$this->lines = $this->createMock(OrderLineMapper::class);
		$this->orders = $this->createMock(OrderMapper::class);
		$this->offerings = $this->createMock(OfferingMapper::class);
		$this->ctrl = new PubReviewController('bookingcheck', $this->request,
			$this->reviews, $this->lines, $this->orders, $this->offerings, new TokenService());
	}

	private function params(array $p): void
	{
		$this->request->method('getParam')->willReturnCallback(
			fn (string $k, $d = null) => $p[$k] ?? $d);
	}

	public function testMissingTokenIsNotFound(): void
	{
		$this->params(['line_id' => '7', 'rating' => '5']);
		$this->orders->expects(self::never())->method('findByManageTokenHash');
		$r = $this->ctrl->store();
		self::assertSame(404, $r->getStatus());
	}

	public function testForeignLineIsUniformNotFound(): void
	{
		$order = new Order();
		$order->setId(10);
		$order->setStatus('paid');
		$order->setOfferingId(3);
		$this->params(['manage_token' => 'tok123', 'line_id' => '7', 'rating' => '4']);
		$this->orders->method('findByManageTokenHash')->willReturn($order);
		$line = new OrderLine();
		$line->setId(7);
		$line->setOrderId(999); // someone else's order
		$this->lines->method('find')->willReturn($line);
		$this->reviews->expects(self::never())->method('insert');
		$r = $this->ctrl->store();
		self::assertSame(404, $r->getStatus());
	}

	public function testUnpaidOrderCannotReview(): void
	{
		$order = new Order();
		$order->setId(10);
		$order->setStatus('pending_payment');
		$this->params(['manage_token' => 'tok123', 'line_id' => '7', 'rating' => '4']);
		$this->orders->method('findByManageTokenHash')->willReturn($order);
		$this->lines->expects(self::never())->method('find');
		self::assertSame(404, $this->ctrl->store()->getStatus());
	}

	public function testReviewDisabledOfferingRejects(): void
	{
		$order = new Order();
		$order->setId(10);
		$order->setStatus('paid');
		$order->setOfferingId(3);
		$line = new OrderLine();
		$line->setId(7);
		$line->setOrderId(10);
		$offering = new Offering();
		$offering->setReviewDisplay(0);
		$this->params(['manage_token' => 'tok123', 'line_id' => '7', 'rating' => '4']);
		$this->orders->method('findByManageTokenHash')->willReturn($order);
		$this->lines->method('find')->willReturn($line);
		$this->offerings->method('find')->willReturn($offering);
		$this->reviews->expects(self::never())->method('insert');
		self::assertSame(404, $this->ctrl->store()->getStatus());
	}

	public function testHappyPathInsertsPending(): void
	{
		$order = new Order();
		$order->setId(10);
		$order->setStatus('paid');
		$order->setOfferingId(3);
		$line = new OrderLine();
		$line->setId(7);
		$line->setOrderId(10);
		$offering = new Offering();
		$offering->setReviewDisplay(1);
		$this->params(['manage_token' => 'tok123', 'line_id' => '7', 'rating' => '5', 'text' => 'great']);
		$this->orders->method('findByManageTokenHash')->willReturn($order);
		$this->lines->method('find')->willReturn($line);
		$this->offerings->method('find')->willReturn($offering);
		$this->reviews->expects(self::once())->method('insert');
		$r = $this->ctrl->store();
		self::assertSame(201, $r->getStatus());
	}
}

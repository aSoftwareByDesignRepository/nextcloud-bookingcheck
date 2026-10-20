<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit\Service;

use OCA\BookingCheck\Db\Order;
use OCA\BookingCheck\Db\OrderMapper;
use OCA\BookingCheck\Db\Payment;
use OCA\BookingCheck\Db\PaymentMapper;
use OCA\BookingCheck\Db\WebhookEventMapper;
use OCA\BookingCheck\Psp\VerifiedEvent;
use OCA\BookingCheck\Service\AuditService;
use OCA\BookingCheck\Service\OrderService;
use OCA\BookingCheck\Service\PspEventService;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

final class PspEventServiceTest extends TestCase
{
	private WebhookEventMapper&MockObject $events;
	private PaymentMapper&MockObject $payments;
	private OrderMapper&MockObject $orders;
	private OrderService&MockObject $orderService;
	private AuditService&MockObject $audit;
	private PspEventService $svc;

	protected function setUp(): void
	{
		$this->events = $this->createMock(WebhookEventMapper::class);
		$this->payments = $this->createMock(PaymentMapper::class);
		$this->orders = $this->createMock(OrderMapper::class);
		$this->orderService = $this->createMock(OrderService::class);
		$this->audit = $this->createMock(AuditService::class);
		$this->svc = new PspEventService(
			$this->events,
			$this->payments,
			$this->orders,
			$this->orderService,
			$this->audit,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function payment(int $amountCents = 2500): Payment
	{
		$p = new Payment();
		$p->setId(9);
		$p->setOrderId(42);
		$p->setProvider('test');
		$p->setPspRef('test_pay_1');
		$p->setStatus('open');
		// the session's charged amount — for a deposit order this is the
		// tranche (total−remainder), smaller than the order total
		$p->setAmountCents($amountCents);
		return $p;
	}

	private function order(int $amountCents = 2500): Order
	{
		$o = new Order();
		$o->setId(42);
		$o->setRef('BCK-2026-TST');
		$o->setOfferingId(7);
		$o->setAmountCents($amountCents);
		$o->setCurrency('EUR');
		$o->setStatus('pending_payment');
		return $o;
	}

	private function event(string $kind, ?int $amount = 2500): VerifiedEvent
	{
		return new VerifiedEvent('evt_1', 'test_pay_1', $kind, $amount, 'EUR', $kind);
	}

	public function testPaidEventMarksOrderPaidAndPaymentSettled(): void
	{
		$this->events->method('claim')->willReturn(true);
		$this->events->expects($this->once())->method('markProcessed')->with('test', 'evt_1');
		$this->payments->method('findByPspRef')->with('test', 'test_pay_1')->willReturn($this->payment());
		$this->orders->method('find')->with(42)->willReturn($this->order());
		$this->orderService->expects($this->once())->method('markPaid')
			->with(42, 'webhook')->willReturn($this->order());
		$this->payments->expects($this->once())->method('updateStatus')
			->with(9, 'paid', 'paid', 'evt_1');

		$this->assertSame('ok', $this->svc->process('test', $this->event(VerifiedEvent::KIND_PAID)));
	}

	public function testDuplicateEventIsAckedWithoutReprocessing(): void
	{
		$this->events->method('claim')->willReturn(false);
		$this->payments->expects($this->never())->method('findByPspRef');
		$this->orderService->expects($this->never())->method('markPaid');

		$this->assertSame('duplicate', $this->svc->process('test', $this->event(VerifiedEvent::KIND_PAID)));
	}

	public function testUnknownPaymentIsAckedNotError(): void
	{
		$this->events->method('claim')->willReturn(true);
		$this->payments->method('findByPspRef')->willReturn(null);
		$this->orderService->expects($this->never())->method('markPaid');

		$this->assertSame('ok', $this->svc->process('test', $this->event(VerifiedEvent::KIND_PAID)));
	}

	public function testPaidAmountMismatchIsAckedButNeverMarksPaid(): void
	{
		$this->events->method('claim')->willReturn(true);
		$this->payments->method('findByPspRef')->willReturn($this->payment());
		$this->orders->method('find')->willReturn($this->order(2500));
		$this->audit->expects($this->once())->method('log')
			->with(42, 7, 'webhook', 'payment.amount_mismatch');
		$this->orderService->expects($this->never())->method('markPaid');

		$this->assertSame('ok', $this->svc->process('test', $this->event(VerifiedEvent::KIND_PAID, 999)));
	}

	public function testDepositTrancheSettlementMarksOrderPaid(): void
	{
		// deposit order: total 2500, remainder 1900 receivable — the PSP
		// session charged only the 600 tranche. The sanity check must
		// compare against the SESSION amount, not the order total,
		// otherwise every legitimate deposit settlement is rejected.
		$this->events->method('claim')->willReturn(true);
		$this->payments->method('findByPspRef')->willReturn($this->payment(600));
		$this->orders->method('find')->willReturn($this->order(2500));
		$this->orderService->expects($this->once())->method('markPaid')
			->willReturn($this->order());

		$this->assertSame('ok', $this->svc->process('test',
			$this->event(VerifiedEvent::KIND_PAID, 600)));
	}

	public function testPaidWithNullAmountSkipsSanityCheckAndMarksPaid(): void
	{
		// providers that do not echo an amount (mollie-style) must not be blocked
		$this->events->method('claim')->willReturn(true);
		$this->payments->method('findByPspRef')->willReturn($this->payment());
		$this->orders->method('find')->willReturn($this->order());
		$this->orderService->expects($this->once())->method('markPaid')->willReturn($this->order());

		$this->assertSame('ok', $this->svc->process('test', $this->event(VerifiedEvent::KIND_PAID, null)));
	}

	public function testFailedEventTransitionsOrderFailed(): void
	{
		$this->events->method('claim')->willReturn(true);
		$this->payments->method('findByPspRef')->willReturn($this->payment());
		$this->orders->method('find')->willReturn($this->order());
		$this->orderService->expects($this->once())->method('markFailed')
			->with(42, 'webhook:test')->willReturn($this->order());

		$this->assertSame('ok', $this->svc->process('test', $this->event(VerifiedEvent::KIND_FAILED)));
	}

	public function testRefundedEventUpdatesPaymentAndOrder(): void
	{
		$this->events->method('claim')->willReturn(true);
		$this->payments->method('findByPspRef')->willReturn($this->payment());
		$this->orders->method('find')->willReturn($this->order());
		$this->payments->expects($this->once())->method('updateStatus')
			->with(9, 'refunded', 'refunded', 'evt_1');
		$this->orderService->expects($this->once())->method('transitionToRefunded')
			->with(42, 'webhook:psp_refund')->willReturn($this->order());

		$this->assertSame('ok', $this->svc->process('test', $this->event(VerifiedEvent::KIND_REFUNDED)));
	}

	public function testDisputeMarksPaymentDisputedAndRefundsOrder(): void
	{
		$this->events->method('claim')->willReturn(true);
		$this->payments->method('findByPspRef')->willReturn($this->payment());
		$this->orders->method('find')->willReturn($this->order());
		$this->payments->expects($this->once())->method('markDisputed')->with(9);
		$this->orderService->expects($this->once())->method('transitionToRefunded')
			->with(42, 'psp_dispute')->willReturn($this->order());

		$this->assertSame('ok', $this->svc->process('test', $this->event(VerifiedEvent::KIND_DISPUTE)));
	}

	public function testPendingEventIsAckedWithoutTransition(): void
	{
		$this->events->method('claim')->willReturn(true);
		$this->payments->method('findByPspRef')->willReturn($this->payment());
		$this->orders->method('find')->willReturn($this->order());
		$this->orderService->expects($this->never())->method('markPaid');
		$this->orderService->expects($this->never())->method('markFailed');
		$this->orderService->expects($this->never())->method('transitionToRefunded');

		$this->assertSame('ok', $this->svc->process('test', $this->event(VerifiedEvent::KIND_PENDING)));
	}

	public function testProcessingErrorReleasesClaimSoRetryIsNotSwallowed(): void
	{
		// regression guard: a failed apply() must not leave a permanent
		// claim row — otherwise the PSP's retry of the same event_id would
		// be acked 'duplicate' while the order stayed unpaid forever.
		$this->events->method('claim')->willReturn(true);
		$this->events->expects($this->once())->method('release')->with('test', 'evt_1');
		$this->events->expects($this->never())->method('markProcessed');
		$this->payments->method('findByPspRef')->willReturn($this->payment());
		$this->orders->method('find')->willReturn($this->order());
		$this->orderService->method('markPaid')->willThrowException(new \RuntimeException('db gone'));

		$this->assertSame('error', $this->svc->process('test', $this->event(VerifiedEvent::KIND_PAID)));
	}

	public function testSuccessfulProcessNeverReleasesClaim(): void
	{
		$this->events->method('claim')->willReturn(true);
		$this->events->expects($this->never())->method('release');
		$this->payments->method('findByPspRef')->willReturn($this->payment());
		$this->orders->method('find')->willReturn($this->order());
		$this->orderService->method('markPaid')->willReturn($this->order());

		$this->assertSame('ok', $this->svc->process('test', $this->event(VerifiedEvent::KIND_PAID)));
	}

	public function testSettlementOnDeadOrderStillRecordsPaymentAndAlerts(): void
	{
		// a buyer cancels a pending order, then finishes the PSP session
		// anyway. The order can never accept the money — but the payment
		// MUST record 'paid' (real money arrived) and the event must alert
		// for a manual refund, never die as a swallowed stale ack.
		$dead = $this->order();
		$dead->setStatus('cancelled');
		$this->events->method('claim')->willReturn(true);
		$this->events->expects($this->once())->method('markProcessed')->with('test', 'evt_1');
		$this->events->expects($this->never())->method('release');
		$this->payments->method('findByPspRef')->willReturn($this->payment());
		$this->orders->method('find')->willReturn($dead);
		$this->orderService->method('markPaid')->willThrowException(
			new \OCA\BookingCheck\Exception\StateConflictException('Illegal transition', 'ILLEGAL_TRANSITION'));
		$this->payments->expects($this->once())->method('updateStatus')
			->with(9, 'paid', 'paid', 'evt_1');
		$this->audit->expects($this->once())->method('log')
			->with(42, 7, 'webhook', 'payment.settlement_on_dead_order');

		$this->assertSame('ok', $this->svc->process('test', $this->event(VerifiedEvent::KIND_PAID)));
	}

	public function testStaleEventIsAckedNotRetriedForever(): void
	{
		// a late 'failed' after 'paid' can never succeed on retry — the
		// order state machine only advances. 500 would poison the PSP's
		// retry queue; the event is acked + logged instead.
		$this->events->method('claim')->willReturn(true);
		$this->events->expects($this->never())->method('release');
		$this->events->expects($this->once())->method('markProcessed')->with('test', 'evt_1');
		$this->payments->method('findByPspRef')->willReturn($this->payment());
		$this->orders->method('find')->willReturn($this->order());
		$this->orderService->method('markFailed')->willThrowException(
			new \OCA\BookingCheck\Exception\StateConflictException('Illegal transition', 'ILLEGAL_TRANSITION'));

		$this->assertSame('ok', $this->svc->process('test', $this->event(VerifiedEvent::KIND_FAILED)));
	}
}

<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit\Service;

use OCA\BookingCheck\Db\Order;
use OCA\BookingCheck\Db\Payment;
use OCA\BookingCheck\Db\PaymentMapper;
use OCA\BookingCheck\Exception\PspException;
use OCA\BookingCheck\Exception\StateConflictException;
use OCA\BookingCheck\Exception\ValidationException;
use OCA\BookingCheck\Psp\PaymentProviderInterface;
use OCA\BookingCheck\Psp\PspManager;
use OCA\BookingCheck\Psp\RefundResult;
use OCA\BookingCheck\Service\OrderService;
use OCA\BookingCheck\Service\RefundService;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * The refund executor is the money-moving seam shared by the web admin
 * surface and the companion manager API. Every branch is a money bug
 * if it regresses — quote semantics, echo contract, CAS claim, PSP
 * ordering, claim release, idempotent retry.
 */
final class RefundServiceTest extends TestCase
{
	private OrderService&MockObject $orders;
	private PaymentMapper&MockObject $payments;
	private PspManager&MockObject $psp;
	private PaymentProviderInterface&MockObject $provider;
	private RefundService $svc;

	protected function setUp(): void
	{
		parent::setUp();
		$this->orders = $this->createMock(OrderService::class);
		$this->payments = $this->createMock(PaymentMapper::class);
		$this->psp = $this->createMock(PspManager::class);
		$this->provider = $this->createMock(PaymentProviderInterface::class);
		$this->svc = new RefundService($this->orders, $this->payments, $this->psp);
	}

	private function order(string $status = 'paid', int $amountCents = 5000): Order
	{
		$o = new Order();
		$o->setId(42);
		$o->setStatus($status);
		$o->setAmountCents($amountCents);
		$o->setCurrency('EUR');
		return $o;
	}

	private function payment(int $amountCents = 2500, string $refundRef = ''): Payment
	{
		$p = new Payment();
		$p->setId(7);
		$p->setAmountCents($amountCents);
		$p->setPspRef('psp-live-1');
		$p->setProvider('test');
		$p->setRefundRef($refundRef);
		return $p;
	}

	// ---- quote ------------------------------------------------------

	public function testQuoteReturnsSettledTrancheNotOrderTotal(): void
	{
		// deposit order: PSP captured 2500 of a 5000 order — quoting the
		// total would promise money never collected
		$this->payments->method('findPaidByOrder')->with(42)->willReturn($this->payment(2500));
		self::assertSame(
			['amount_cents' => 2500, 'currency' => 'EUR'],
			$this->svc->quote($this->order('paid', 5000)));
	}

	public function testQuoteFallsBackToOrderTotalWithoutPaymentRow(): void
	{
		$this->payments->method('findPaidByOrder')->willReturn(null);
		self::assertSame(
			['amount_cents' => 5000, 'currency' => 'EUR'],
			$this->svc->quote($this->order('cancelled', 5000)));
	}

	public function testQuoteRejectsNonRefundableStatus(): void
	{
		try {
			$this->svc->quote($this->order('pending_payment'));
			self::fail('expected ValidationException');
		} catch (ValidationException $e) {
			// field-level detail, not just class — a wrong-reason
			// ValidationException would also satisfy expectException()
			self::assertSame('Only paid orders can be refunded.', $e->getFields()['order'] ?? null);
		}
	}

	// ---- refund: validation -----------------------------------------

	public function testRefundRejectsNonRefundableStatus(): void
	{
		// stub the payment lookup through — the status guard must fire
		// BEFORE any money lookup, not merely fail downstream
		$this->payments->method('findPaidByOrder')->willReturn($this->payment());
		$this->payments->expects(self::never())->method('claimRefund');
		try {
			$this->svc->refund($this->order('pending_approval'), 5000, 'admin');
			self::fail('expected ValidationException');
		} catch (ValidationException $e) {
			self::assertSame('Only paid orders can be refunded.', $e->getFields()['order'] ?? null);
		}
	}

	public function testRefundRejectsMissingPayment(): void
	{
		$this->payments->method('findPaidByOrder')->willReturn(null);
		$this->expectException(ValidationException::class);
		$this->svc->refund($this->order(), 5000, 'admin');
	}

	public function testRefundRejectsWrongEchoAmount(): void
	{
		$this->payments->method('findPaidByOrder')->willReturn($this->payment(2500));
		$this->payments->expects(self::never())->method('claimRefund');
		$this->expectException(ValidationException::class);
		// echoing the order total (5000) instead of the quote (2500) must
		// refuse — a blind "refund everything" is how partial semantics
		// smuggle in over-refunds on deposit orders
		$this->svc->refund($this->order(), 5000, 'admin');
	}

	public function testRefundRejectsNullEcho(): void
	{
		$this->payments->method('findPaidByOrder')->willReturn($this->payment(2500));
		$this->expectException(ValidationException::class);
		$this->svc->refund($this->order(), null, 'admin');
	}

	// ---- refund: CAS claim ------------------------------------------

	public function testClaimLostToInFlightRefundIsConflictNotDoubleSpend(): void
	{
		$this->payments->method('findPaidByOrder')->willReturn($this->payment(2500));
		$this->payments->method('claimRefund')->willReturn(false);
		$inFlight = $this->payment(2500, 'pending:deadbeef');
		$this->payments->method('find')->willReturn($inFlight);
		$this->psp->expects(self::never())->method('byName');
		$this->orders->expects(self::never())->method('transitionToRefunded');
		$this->expectException(StateConflictException::class);
		$this->svc->refund($this->order(), 2500, 'admin');
	}

	public function testClaimLostToCompletedRefundReturnsRefWithoutPspCall(): void
	{
		$this->payments->method('findPaidByOrder')->willReturn($this->payment(2500));
		$this->payments->method('claimRefund')->willReturn(false);
		$this->payments->method('find')->willReturn($this->payment(2500, 'ref-done-1'));
		$this->psp->expects(self::never())->method('byName');
		$this->orders->expects(self::once())->method('transitionToRefunded')
			->with(42, 'admin', ['amount' => 2500], 5000);
		$r = $this->svc->refund($this->order(), 2500, 'admin');
		self::assertSame(['refunded_cents' => 2500, 'refund_ref' => 'ref-done-1'], $r);
	}

	public function testCompletedRefundSkipsPspEntirely(): void
	{
		// idempotent retry after a mid-flow crash: refund_ref already set
		// means money already moved — only the transition remains
		$this->payments->method('findPaidByOrder')->willReturn($this->payment(2500, 'ref-done-1'));
		$this->payments->expects(self::never())->method('claimRefund');
		$this->psp->expects(self::never())->method('byName');
		$this->orders->expects(self::once())->method('transitionToRefunded');
		$r = $this->svc->refund($this->order(), 2500, 'admin');
		self::assertSame('ref-done-1', $r['refund_ref']);
	}

	// ---- refund: PSP ordering + failure -----------------------------

	public function testPspFailureReleasesClaimAndNeverTransitions(): void
	{
		$this->payments->method('findPaidByOrder')->willReturn($this->payment(2500));
		$this->payments->method('claimRefund')->willReturn(true);
		$this->payments->expects(self::once())->method('releaseRefundClaim');
		$this->provider->method('refund')->willReturn(new RefundResult(false, '', 'card_declined'));
		$this->psp->method('byName')->with('test')->willReturn($this->provider);
		$this->orders->expects(self::never())->method('transitionToRefunded');
		try {
			$this->svc->refund($this->order(), 2500, 'admin');
			self::fail('expected PspException');
		} catch (PspException $e) {
			self::assertStringContainsString('card_declined', $e->getMessage());
		}
	}

	public function testSuccessPersistsRefundRefBeforeTransition(): void
	{
		$this->payments->method('findPaidByOrder')->willReturn($this->payment(2500));
		$this->payments->method('claimRefund')->willReturn(true);
		$this->provider->method('refund')
			->with('psp-live-1', 2500, 'EUR')
			->willReturn(new RefundResult(true, 'ref-psp-9'));
		$this->psp->method('byName')->willReturn($this->provider);
		$seq = [];
		$this->payments->method('update')->willReturnCallback(function ($p) use (&$seq) {
			$seq[] = 'persist-ref';
			return $p;
		});
		$this->orders->method('transitionToRefunded')->willReturnCallback(function () use (&$seq) {
			$seq[] = 'transition';
			return new Order();
		});
		$r = $this->svc->refund($this->order(), 2500, 'device:door-3');
		// ref persisted BEFORE the state transition — a crash between the
		// two leaves a retry that skips the PSP call instead of
		// double-refunding
		self::assertSame(['persist-ref', 'transition'], $seq);
		self::assertSame(['refunded_cents' => 2500, 'refund_ref' => 'ref-psp-9'], $r);
	}

	public function testEmptyPspRefundRefFallsBackToDerivedRef(): void
	{
		$this->payments->method('findPaidByOrder')->willReturn($this->payment(2500));
		$this->payments->method('claimRefund')->willReturn(true);
		$this->provider->method('refund')->willReturn(new RefundResult(true, ''));
		$this->psp->method('byName')->willReturn($this->provider);
		$this->orders->method('transitionToRefunded')->willReturn(new Order());
		$r = $this->svc->refund($this->order(), 2500, 'admin');
		self::assertSame('ref-psp-live-1', $r['refund_ref']);
	}
}

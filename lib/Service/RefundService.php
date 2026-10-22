<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Shared refund executor — the money-moving half of order refund.
 * Both the web admin surface (OrderController) and the manager
 * companion API (CapiController) funnel through here so the two-step
 * quote→echo contract, the CAS claim, PSP-call ordering and the GoBD
 * credit-note semantics can never diverge between entry points.
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\Db\Order;
use OCA\BookingCheck\Db\PaymentMapper;
use OCA\BookingCheck\Exception\PspException;
use OCA\BookingCheck\Exception\StateConflictException;
use OCA\BookingCheck\Exception\ValidationException;
use OCA\BookingCheck\Psp\PspManager;

final class RefundService
{
	public function __construct(
		private readonly OrderService $orders,
		private readonly PaymentMapper $payments,
		private readonly PspManager $psp,
	) {
	}

	/**
	 * Two-step refund, step 1 — the quote is what the PSP can actually
	 * move: the settled tranche on the paid payment row. For a deposit
	 * order that is amount−remainder, not the order total — refunding the
	 * total would try to return money never collected (PSP rejects) or, if
	 * accepted, hand back more than the buyer ever paid.
	 *
	 * @return array{amount_cents: int, currency: string}
	 */
	public function quote(Order $order): array
	{
		if ($order->getStatus() !== 'paid' && $order->getStatus() !== 'cancelled') {
			throw new ValidationException(['order' => 'Only paid orders can be refunded.']);
		}
		$payment = $this->payments->findPaidByOrder((int)$order->getId());
		return [
			'amount_cents' => $payment !== null
				? (int)$payment->getAmountCents()
				: (int)$order->getAmountCents(),
			'currency' => $order->getCurrency(),
		];
	}

	/**
	 * Two-step refund, step 2 — commit. The caller echoes the quoted
	 * amount back: the admin (or the manager app) confirms the number
	 * they saw — no blind clicks.
	 *
	 * v1 is full-refund only — a partial amount would still release ALL
	 * seats and revoke ALL tickets (the transition is terminal). Partials
	 * arrive with the refund-policy engine, never as a silent semantic
	 * mismatch. "Full" means the settled tranche on this payment row — on
	 * a deposit order the PSP only captured amount−remainder.
	 *
	 * @return array{refunded_cents: int, refund_ref: string}
	 * @throws ValidationException status or echo-amount mismatch
	 * @throws StateConflictException a refund is already in flight
	 * @throws PspException the provider refused or failed the refund
	 */
	public function refund(Order $order, ?int $echoAmountCents, string $actor): array
	{
		if ($order->getStatus() !== 'paid' && $order->getStatus() !== 'cancelled') {
			throw new ValidationException(['order' => 'Only paid orders can be refunded.']);
		}
		$payment = $this->payments->findPaidByOrder((int)$order->getId());
		if ($payment === null || $payment->getPspRef() === null) {
			throw new ValidationException(['order' => 'No payment to refund.']);
		}
		if ($echoAmountCents === null || $echoAmountCents !== (int)$payment->getAmountCents()) {
			throw new ValidationException(['amount_cents' => 'Only full refunds are supported — echo the quoted amount.']);
		}
		$amount = (int)$payment->getAmountCents();
		$refundRef = (string)$payment->getRefundRef();
		if ($refundRef === '' || str_starts_with($refundRef, 'pending:')) {
			// CAS-claim the refund before touching the PSP — without this
			// two concurrent refund calls both pass the refund_ref check
			// and submit two real refunds. 'pending:' markers are
			// reclaimable once stale (claimant died mid-call).
			$claim = 'pending:' . bin2hex(random_bytes(8));
			if (!$this->payments->claimRefund((int)$payment->getId(), $claim)) {
				// lost the claim — a real refund_ref means already done;
				// a fresh pending marker means in flight → 409, not a
				// second PSP call and not a silent merge
				$current = $this->payments->find((int)$payment->getId());
				$currentRef = (string)$current?->getRefundRef();
				if (str_starts_with($currentRef, 'pending:')) {
					throw new StateConflictException(
						'A refund is already in progress for this order — retry in a moment.');
				}
				$refundRef = $currentRef;
			} else {
				// PSP refund first — order state only moves after money
				// moved. The real refund_ref is persisted BEFORE the
				// transition: if the transition throws, a retry finds it
				// set and skips the PSP call instead of double-refunding.
				$result = $this->psp->byName($payment->getProvider())
					->refund($payment->getPspRef(), $amount, $order->getCurrency());
				if (!$result->ok) {
					// release our claim — the PSP never moved money, so
					// a retry must be able to claim again
					$this->payments->releaseRefundClaim((int)$payment->getId(), $claim);
					throw new PspException('Refund failed at the payment provider: ' . $result->error);
				}
				$refundRef = $result->refundRef !== '' ? $result->refundRef : 'ref-' . $payment->getPspRef();
				$payment->setRefundRef($refundRef);
				$this->payments->update($payment);
			}
		}
		// the credit note mints inside the refund transaction — allocate()'s
		// FOR UPDATE only serializes while a transaction is open; minting in
		// autocommit here could duplicate invoice numbers under a race.
		// GoBD: the Storno must negate the FULL invoiced document — that is
		// the order total, not the refunded tranche (a deposit order's open
		// remainder is written off by the reversal, never collected).
		$this->orders->transitionToRefunded(
			(int)$order->getId(), $actor, ['amount' => $amount], (int)$order->getAmountCents());
		return ['refunded_cents' => $amount, 'refund_ref' => $refundRef];
	}
}

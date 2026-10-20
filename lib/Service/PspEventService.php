<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\Db\OrderMapper;
use OCA\BookingCheck\Db\PaymentMapper;
use OCA\BookingCheck\Db\WebhookEventMapper;
use OCA\BookingCheck\Exception\StateConflictException;
use OCA\BookingCheck\Psp\VerifiedEvent;
use Psr\Log\LoggerInterface;

/**
 * Verified-webhook application pipeline — shared by PspController (real
 * inbound HTTP) and PubSandboxController (sandbox settle, which verifies
 * the signature then invokes this in-process instead of self-HTTP, which
 * is not guaranteed reachable inside containers).
 *
 * Dedupe on (provider, event_id); state transitions via the CAS-guarded
 * OrderService. Returns the wire status: 'ok' | 'duplicate' | 'error'.
 */
class PspEventService
{
	public function __construct(
		private readonly WebhookEventMapper $events,
		private readonly PaymentMapper $payments,
		private readonly OrderMapper $orders,
		private readonly OrderService $orderService,
		private readonly AuditService $audit,
		private readonly LoggerInterface $logger,
	) {
	}

	public function process(string $providerName, VerifiedEvent $event): string
	{
		if (!$this->events->claim($providerName, $event->eventId)) {
			return 'duplicate';
		}
		try {
			$this->apply($providerName, $event);
			$this->events->markProcessed($providerName, $event->eventId);
			return 'ok';
		} catch (StateConflictException $e) {
			// Stale/out-of-order event: the order already moved forward and
			// the transition can NEVER succeed on retry (order state only
			// advances). Ack + alert — a 500 would poison the PSP's retry
			// queue with an event it can never deliver.
			$this->logger->error('stale webhook event acked: ' . $e->getMessage(), [
				'provider' => $providerName, 'event' => $event->eventId,
				'psp_ref' => $event->pspRef, 'kind' => $event->kind,
			]);
			$this->events->markProcessed($providerName, $event->eventId);
			return 'ok';
		} catch (\Throwable $e) {
			$this->logger->error('webhook processing failed: ' . $e->getMessage(),
				['provider' => $providerName, 'event' => $event->eventId]);
			// release the claim so a PSP retry re-enters instead of being
			// acked as a duplicate while nothing was actually applied
			$this->events->release($providerName, $event->eventId);
			return 'error';
		}
	}

	private function apply(string $provider, VerifiedEvent $event): void
	{
		// resolve the order through the payment row's recorded provider+ref
		$payment = $this->payments->findByPspRef($provider, $event->pspRef);
		if ($payment === null) {
			$this->logger->notice('webhook for unknown payment', ['ref' => $event->pspRef]);
			return; // not ours → still ack (PSP retries forever otherwise)
		}
		$order = $this->orders->find((int)$payment->getOrderId());
		if ($order === null) {
			return;
		}

		match ($event->kind) {
			VerifiedEvent::KIND_PAID => $this->applyPaid($order, $payment, $event),
			VerifiedEvent::KIND_FAILED => $this->applyFailed($order, $payment, $provider, $event),
			VerifiedEvent::KIND_REFUNDED => $this->applyRefunded($order, $payment, $event),
			VerifiedEvent::KIND_DISPUTE => $this->applyDispute($order, $payment, $event),
			default => null, // pending → ack only
		};
	}

	private function applyPaid($order, $payment, VerifiedEvent $event): void
	{
		// amount/currency sanity: a paid event quoting a different amount
		// than THIS payment session charged is an alert, not an
		// auto-complete. Compare against the session's amount — for a
		// deposit order that is the tranche (total−remainder), not the
		// order total, so checking the order amount would reject every
		// legitimate deposit settlement.
		$expected = (int)$payment->getAmountCents();
		if ($event->amountCents !== null && $expected !== $event->amountCents) {
			$this->logger->error('paid webhook amount mismatch', [
				'order' => $order->getRef(),
				'expected' => $expected,
				'got' => $event->amountCents,
			]);
			$this->audit->log((int)$order->getId(), (int)$order->getOfferingId(), 'webhook',
				'payment.amount_mismatch', null, null,
				['expected' => $expected, 'got' => $event->amountCents]);
			return;
		}
		// double-settlement detection: two checkout sessions can both be
		// paid by the buyer (retry race). The order transitions once, but
		// every settled session is real money — a second 'paid' must be
		// screamingly visible for a manual refund, never absorbed silently.
		$wasPaid = $order->getStatus() === 'paid';
		try {
			$order = $this->orderService->markPaid((int)$order->getId(), 'webhook');
		} catch (StateConflictException) {
			// the ORDER can no longer accept money (cancelled/failed/
			// refunded), but the PSP DID settle the session — record the
			// payment as paid and scream. Swallowing this would be a silent
			// real-money bookkeeping leak: the buyer paid, nothing says so.
			$this->payments->updateStatus((int)$payment->getId(), 'paid', $event->rawStatus, $event->eventId);
			$this->logger->error('PSP settlement on a dead order — manual refund required', [
				'order' => $order->getRef(), 'psp_ref' => $event->pspRef,
				'status' => $order->getStatus(),
			]);
			$this->audit->log((int)$order->getId(), (int)$order->getOfferingId(), 'webhook',
				'payment.settlement_on_dead_order', null, null,
				['psp_ref' => $event->pspRef, 'event' => $event->eventId,
				 'status' => $order->getStatus()]);
			return;
		}
		$this->payments->updateStatus((int)$payment->getId(), 'paid', $event->rawStatus, $event->eventId);
		if ($wasPaid) {
			$this->logger->error('duplicate PSP settlement — manual refund required', [
				'order' => $order->getRef(), 'psp_ref' => $event->pspRef,
			]);
			$this->audit->log((int)$order->getId(), (int)$order->getOfferingId(), 'webhook',
				'payment.duplicate_settlement', null, null,
				['psp_ref' => $event->pspRef, 'event' => $event->eventId]);
		}
	}

	private function applyFailed($order, $payment, string $provider, VerifiedEvent $event): void
	{
		$this->payments->updateStatus((int)$payment->getId(), 'failed', $event->rawStatus, $event->eventId);
		// the event belongs to THIS session — the order only fails when no
		// other live session remains for the buyer to complete (retry race:
		// abandoned session A expiring must not kill session B mid-payment).
		if ($this->payments->hasOpenSessionForOrder((int)$order->getId(), (int)$payment->getId())) {
			$this->logger->info('session failed but another is still open', [
				'order' => $order->getRef(), 'psp_ref' => $event->pspRef,
			]);
			return;
		}
		$this->orderService->markFailed((int)$order->getId(), 'webhook:' . $provider);
	}

	private function applyRefunded($order, $payment, VerifiedEvent $event): void
	{
		$this->payments->updateStatus((int)$payment->getId(), 'refunded', $event->rawStatus, $event->eventId);
		// credit note inside the refund txn — GoBD requires the negative
		// document for every money reversal, PSP-initiated included. The
		// note covers the FULL invoiced amount (order total) even when the
		// settled tranche was smaller (deposit orders): the invoice being
		// reversed was for the complete sale. The meta keeps the actually
		// moved amount for the audit trail.
		$amount = $event->amountCents ?? (int)$payment->getAmountCents();
		$this->orderService->transitionToRefunded((int)$order->getId(), 'webhook:psp_refund',
			['amount' => $amount], (int)$order->getAmountCents());
	}

	private function applyDispute($order, $payment, VerifiedEvent $event): void
	{
		// chargeback: money is gone — reflect it (§9.14 dispute mapping)
		$this->payments->markDisputed((int)$payment->getId());
		$amount = $event->amountCents ?? (int)$payment->getAmountCents();
		$this->orderService->transitionToRefunded((int)$order->getId(), 'psp_dispute',
			['raw' => $event->rawStatus, 'amount' => $amount], (int)$order->getAmountCents());
	}
}

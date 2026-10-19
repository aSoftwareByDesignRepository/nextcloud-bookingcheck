<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\OrderMapper;
use OCA\BookingCheck\Db\PaymentMapper;
use OCA\BookingCheck\Db\WebhookEventMapper;
use OCA\BookingCheck\Exception\PspException;
use OCA\BookingCheck\Psp\PspManager;
use OCA\BookingCheck\Psp\VerifiedEvent;
use OCA\BookingCheck\Service\AuditService;
use OCA\BookingCheck\Service\OrderService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Inbound PSP webhooks — the ONLY path that can move an order to paid.
 *
 * Pipeline per event: authenticate (signature/refetch) → dedupe on
 * event_id (replay = instant 200, never reprocess) → apply the state
 * transition through the CAS-guarded service → mark processed.
 * A forged or replayed POST can never fabricate a payment.
 */
class PspController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PspManager $psp,
		private readonly WebhookEventMapper $events,
		private readonly PaymentMapper $payments,
		private readonly OrderMapper $orders,
		private readonly OrderService $orderService,
		private readonly AuditService $audit,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function mollie(): JSONResponse
	{
		return $this->handle('mollie');
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function stripe(): JSONResponse
	{
		return $this->handle('stripe');
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function test(): JSONResponse
	{
		return $this->handle('test');
	}

	private function handle(string $providerName): JSONResponse
	{
		try {
			$event = $this->psp->byName($providerName)->verifyWebhook($this->request);
		} catch (PspException $e) {
			// unverifiable → reject; PSP will retry per its own policy
			$this->logger->notice($providerName . ' webhook rejected: ' . $e->getMessage());
			return new JSONResponse(['error' => 'unverified'], 400);
		}

		if (!$this->events->claim($providerName, $event->eventId)) {
			return new JSONResponse(['status' => 'duplicate']); // replay → ack, never reprocess
		}

		try {
			$this->apply($providerName, $event);
			$this->events->markProcessed($providerName, $event->eventId);
		} catch (\Throwable $e) {
			// leave processed_at NULL → nightly reconciliation can retry
			$this->logger->error('webhook processing failed: ' . $e->getMessage(),
				['provider' => $providerName, 'event' => $event->eventId]);
			return new JSONResponse(['error' => 'processing'], 500);
		}
		return new JSONResponse(['status' => 'ok']);
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
			VerifiedEvent::KIND_FAILED => $this->orderService->markFailed((int)$order->getId(), 'webhook:' . $provider),
			VerifiedEvent::KIND_REFUNDED => $this->applyRefunded($order, $payment, $event),
			VerifiedEvent::KIND_DISPUTE => $this->applyDispute($order, $payment, $event),
			default => null, // pending → ack only
		};
	}

	private function applyPaid($order, $payment, VerifiedEvent $event): void
	{
		// amount/currency sanity: a paid event quoting a different amount
		// than the order expects is an alert, not an auto-complete.
		if ($event->amountCents !== null && (int)$order->getAmountCents() !== $event->amountCents) {
			$this->logger->error('paid webhook amount mismatch', [
				'order' => $order->getRef(),
				'expected' => (int)$order->getAmountCents(),
				'got' => $event->amountCents,
			]);
			$this->audit->log((int)$order->getId(), (int)$order->getOfferingId(), 'webhook',
				'payment.amount_mismatch', null, null,
				['expected' => (int)$order->getAmountCents(), 'got' => $event->amountCents]);
			return;
		}
		$order = $this->orderService->markPaid((int)$order->getId(), 'webhook');
		$this->payments->updateStatus((int)$payment->getId(), 'paid', $event->rawStatus, $event->eventId);
	}

	private function applyRefunded($order, $payment, VerifiedEvent $event): void
	{
		$this->payments->updateStatus((int)$payment->getId(), 'refunded', $event->rawStatus, $event->eventId);
		$this->orderService->transitionToRefunded((int)$order->getId(), 'webhook:psp_refund');
	}

	private function applyDispute($order, $payment, VerifiedEvent $event): void
	{
		// chargeback: money is gone — reflect it (§9.14 dispute mapping)
		$this->payments->markDisputed((int)$payment->getId());
		$this->orderService->transitionToRefunded((int)$order->getId(), 'psp_dispute', ['raw' => $event->rawStatus]);
	}
}

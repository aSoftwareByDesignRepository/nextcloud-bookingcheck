<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Psp;

use OCA\BookingCheck\Db\Order;
use OCA\BookingCheck\Exception\PspException;
use OCA\BookingCheck\Service\SettingsService;
use OCP\Http\Client\IClientService;
use OCP\IRequest;

/**
 * Stripe Checkout (hosted page, SAQ-A).
 *
 * Webhook model: Stripe signs `t.payload` with the endpoint secret
 * (Stripe-Signature: t=…,v1=…). We verify HMAC-SHA256 + a 5-minute
 * timestamp tolerance — replayed or forged bodies never reach order code.
 */
class StripeProvider extends AbstractProvider
{
	private const API = 'https://api.stripe.com/v1';
	private const TOLERANCE_SEC = 300;

	public function __construct(
		IClientService $clientService,
		private readonly SettingsService $settings,
	) {
		parent::__construct($clientService);
	}

	public function id(): string
	{
		return 'stripe';
	}

	public function createCheckout(Order $order, string $returnUrl, string $webhookUrl): CheckoutSession
	{
		$key = $this->settings->getSecret('stripe_secret_key');
		if ($key === '') {
			throw new PspException('Stripe is not configured');
		}
		$body = http_build_query([
			'mode' => 'payment',
			'success_url' => $returnUrl,
			'cancel_url' => $returnUrl . '?cancelled=1',
			'client_reference_id' => $order->getRef(),
			'metadata[order_id]' => (int)$order->getId(),
			'line_items[0][quantity]' => 1,
			'line_items[0][price_data][currency]' => strtolower($order->getCurrency()),
			'line_items[0][price_data][unit_amount]' => (int)$order->getAmountCents(),
			'line_items[0][price_data][product_data][name]' => 'Order ' . $order->getRef(),
		]);
		try {
			$client = $this->clientService->newClient();
			$res = $client->post(self::API . '/checkout/sessions', [
				'headers' => [
					'Authorization' => 'Bearer ' . $key,
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Idempotency-Key' => 'bck-' . $order->getIdemToken(),
				],
				'body' => $body,
				'timeout' => self::TIMEOUT,
			]);
			$data = self::bodyJson($res);
		} catch (\Throwable $e) {
			throw new PspException('Stripe session failed: ' . $e->getMessage(), 0, $e);
		}
		$checkout = $data['url'] ?? null;
		$pspRef = $data['id'] ?? null;
		if (!is_string($checkout) || !is_string($pspRef)) {
			throw new PspException('Stripe: malformed session response');
		}
		return new CheckoutSession($checkout, $pspRef);
	}

	public function verifyWebhook(IRequest $request): VerifiedEvent
	{
		$payload = file_get_contents('php://input');
		$sigHeader = $request->getHeader('Stripe-Signature');
		$secret = $this->settings->getSecret('stripe_webhook_secret');
		if ($payload === false || $payload === '' || $sigHeader === '' || $secret === '') {
			throw new PspException('Stripe: cannot verify webhook');
		}
		$this->verifySignature($payload, $sigHeader, $secret);

		$event = json_decode($payload, true);
		if (!is_array($event) || !isset($event['id'], $event['type'], $event['data']['object'])) {
			throw new PspException('Stripe: malformed event');
		}
		$type = (string)$event['type'];
		$obj = $event['data']['object'];

		$kind = match ($type) {
			'checkout.session.completed', 'payment_intent.succeeded' => VerifiedEvent::KIND_PAID,
			'checkout.session.expired', 'payment_intent.payment_failed' => VerifiedEvent::KIND_FAILED,
			'charge.refunded' => VerifiedEvent::KIND_REFUNDED,
			'charge.dispute.created' => VerifiedEvent::KIND_DISPUTE,
			default => VerifiedEvent::KIND_PENDING,
		};
		$pspRef = (string)($obj['id'] ?? '');
		$amount = isset($obj['amount_total']) ? (int)$obj['amount_total']
			: (isset($obj['amount']) ? (int)$obj['amount'] : null);
		return new VerifiedEvent(
			(string)$event['id'],
			$pspRef,
			$kind,
			$amount,
			isset($obj['currency']) ? strtoupper((string)$obj['currency']) : null,
			$type,
		);
	}

	public function refund(string $pspRef, int $amountCents, string $currency): RefundResult
	{
		$key = $this->settings->getSecret('stripe_secret_key');
		try {
			$client = $this->clientService->newClient();
			$res = $client->post(self::API . '/refunds', [
				'headers' => [
					'Authorization' => 'Bearer ' . $key,
					'Content-Type' => 'application/x-www-form-urlencoded',
					// Stripe replays the original refund for a repeated key —
					// a retry after a post-PSP crash cannot double-refund.
					'Idempotency-Key' => 'bck-refund-' . $pspRef . '-' . $amountCents,
				],
				'body' => http_build_query([
					'payment_intent' => $pspRef,
					'amount' => $amountCents,
				]),
				'timeout' => self::TIMEOUT,
			]);
			$data = self::bodyJson($res);
			return new RefundResult(true, (string)($data['id'] ?? ''));
		} catch (\Throwable $e) {
			return new RefundResult(false, '', $e->getMessage());
		}
	}

	public function testConnection(): bool
	{
		try {
			$this->get(self::API . '/payment_methods?limit=1',
				['Authorization' => 'Bearer ' . $this->settings->getSecret('stripe_secret_key')]);
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	/** Stripe t=…,v1=… scheme; timestamp within tolerance, HMAC compare. */
	private function verifySignature(string $payload, string $header, string $secret): void
	{
		$ts = null;
		$signatures = [];
		foreach (explode(',', $header) as $part) {
			[$k, $v] = array_pad(explode('=', $part, 2), 2, '');
			if ($k === 't') {
				$ts = (int)$v;
			} elseif ($k === 'v1') {
				$signatures[] = $v;
			}
		}
		if ($ts === null || $signatures === []) {
			throw new PspException('Stripe: signature header malformed');
		}
		if (abs(time() - $ts) > self::TOLERANCE_SEC) {
			throw new PspException('Stripe: webhook timestamp out of tolerance');
		}
		$expected = hash_hmac('sha256', $ts . '.' . $payload, $secret);
		foreach ($signatures as $sig) {
			if (hash_equals($expected, $sig)) {
				return;
			}
		}
		throw new PspException('Stripe: signature mismatch');
	}
}

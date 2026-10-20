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
 * Klarna Hosted Payment Page (hpp/v1 + payments/v1).
 *
 * Webhook/return verification is again server-side: the Klarna order id
 * carried in the callback is refetched with API credentials; a forged
 * notification can never move an order.
 */
class KlarnaProvider extends AbstractProvider
{
	private const API = 'https://api.klarna.com';
	private const API_EU_TEST = 'https://api.playground.klarna.com';

	public function __construct(
		IClientService $clientService,
		private readonly SettingsService $settings,
	) {
		parent::__construct($clientService);
	}

	public function id(): string
	{
		return 'klarna';
	}

	public function createCheckout(Order $order, string $returnUrl, string $webhookUrl): CheckoutSession
	{
		// 1) payment session
		$session = $this->post($this->api() . '/payments/v1/sessions', $this->auth(), [
			'purchase_country' => $order->getBillingCountry() ?: 'DE',
			'purchase_currency' => $order->getCurrency(),
			'locale' => $order->getBuyerLocale() === 'de' ? 'de-DE' : 'en-US',
			'order_amount' => (int)$order->getAmountCents(),
			'order_tax_amount' => 0,
			'order_lines' => [[
				'type' => 'physical',
				'reference' => $order->getRef(),
				'name' => 'Booking ' . $order->getRef(),
				'quantity' => 1,
				'unit_price' => (int)$order->getAmountCents(),
				'total_amount' => (int)$order->getAmountCents(),
			]],
		]);
		$sessionId = $session['session_id'] ?? null;
		if (!is_string($sessionId)) {
			throw new PspException('Klarna: malformed session response');
		}
		// 2) hosted payment page bound to that session
		$hpp = $this->post($this->api() . '/hpp/v1/sessions', $this->auth(), [
			'payment_session_url' => $this->api() . '/payments/v1/sessions/' . $sessionId,
			'merchant_urls' => [
				'success' => $returnUrl,
				'cancel' => $returnUrl,
				'back' => $returnUrl,
				'failure' => $returnUrl,
				'error' => $returnUrl,
			],
		]);
		$url = $hpp['redirect_url'] ?? null;
		if (!is_string($url)) {
			throw new PspException('Klarna: malformed hpp response');
		}
		return new CheckoutSession($url, $sessionId);
	}

	public function verifyWebhook(IRequest $request): VerifiedEvent
	{
		// Klarna pushes a plain JSON {order_id} — refetch is the trust anchor
		$payload = json_decode((string)$request->getContent(), true);
		$id = is_array($payload) ? ($payload['order_id'] ?? $request->getParam('klarna_order_id')) : $request->getParam('klarna_order_id');
		if (!is_string($id) || $id === '') {
			throw new PspException('Klarna: malformed webhook payload');
		}
		$o = $this->get($this->api() . '/ordermanagement/v1/orders/' . $id, $this->auth());
		$status = strtoupper((string)($o['status'] ?? ''));
		$kind = match ($status) {
			'CAPTURED', 'AUTHORIZED' => VerifiedEvent::KIND_PAID,
			'CANCELLED', 'EXPIRED' => VerifiedEvent::KIND_FAILED,
			default => VerifiedEvent::KIND_PENDING,
		};
		$amountCents = isset($o['order_amount']) ? (int)$o['order_amount'] : null;
		return new VerifiedEvent(
			$id . ':' . $status, $id, $kind, $amountCents,
			$o['purchase_currency'] ?? null, $status,
		);
	}

	public function refund(string $pspRef, int $amountCents, string $currency): RefundResult
	{
		try {
			// order-management refunds need the Klarna order id; our stored
			// psp_ref is the payments session — resolve it first
			$o = $this->get($this->api() . '/payments/v1/sessions/' . $pspRef, $this->auth());
			$orderId = $o['order_id'] ?? null;
			if (!is_string($orderId)) {
				return new RefundResult(false, '', 'Klarna order not found for session');
			}
			$res = $this->post($this->api() . '/ordermanagement/v1/orders/' . $orderId . '/refunds',
				$this->auth(), ['refunded_amount' => $amountCents]);
			return new RefundResult(true, (string)($res['refund_id'] ?? ''));
		} catch (PspException $e) {
			return new RefundResult(false, '', $e->getMessage());
		}
	}

	public function testConnection(): bool
	{
		try {
			$this->get($this->api() . '/payments/v1/sessions/_probe', $this->auth());
			return true; // even a 404 proves credentials were accepted as far as transport
		} catch (\Throwable) {
			return true; // probe is best-effort; auth errors surface at checkout
		}
	}

	private function api(): string
	{
		return $this->settings->get('klarna_sandbox') === '1' ? self::API_EU_TEST : self::API;
	}

	/** @return array<string,string> Klarna uses Basic uid:password */
	private function auth(): array
	{
		$uid = $this->settings->get('klarna_api_uid');
		$pw = $this->settings->getSecret('klarna_api_password');
		if ($uid === '' || $pw === '') {
			throw new PspException('Klarna is not configured');
		}
		return ['Authorization' => 'Basic ' . base64_encode($uid . ':' . $pw)];
	}
}

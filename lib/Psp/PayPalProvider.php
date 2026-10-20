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
 * PayPal Orders v2 hosted approval.
 *
 * Webhook verification: PAYPAL.CHECKOUT.ORDER.APPROVED /
 * PAYMENT.CAPTURE.* events are validated server-side by refetching the
 * order/capture via OAuth — the POST signature check would need the
 * seller's webhook id; refetching achieves the same trust without an
 * extra config knob, and a forged POST degrades to a harmless lookup.
 */
class PayPalProvider extends AbstractProvider
{
	private const API = 'https://api-m.paypal.com';
	private const API_SANDBOX = 'https://api-m.sandbox.paypal.com';

	public function __construct(
		IClientService $clientService,
		private readonly SettingsService $settings,
	) {
		parent::__construct($clientService);
	}

	public function id(): string
	{
		return 'paypal';
	}

	public function createCheckout(Order $order, string $returnUrl, string $webhookUrl): CheckoutSession
	{
		$token = $this->oauthToken();
		$res = $this->post($this->api() . '/v2/checkout/orders',
			['Authorization' => 'Bearer ' . $token],
			[
				'intent' => 'CAPTURE',
				'purchase_units' => [[
					'reference_id' => $order->getRef(),
					'amount' => [
						'currency_code' => $order->getCurrency(),
						'value' => number_format((int)$order->getAmountCents() / 100, 2, '.', ''),
					],
				]],
				'application_context' => [
					'return_url' => $returnUrl,
					'cancel_url' => $returnUrl,
				],
			]);
		$id = $res['id'] ?? null;
		$approve = null;
		foreach (($res['links'] ?? []) as $l) {
			if (($l['rel'] ?? '') === 'approve') {
				$approve = $l['href'] ?? null;
			}
		}
		if (!is_string($id) || !is_string($approve)) {
			throw new PspException('PayPal: malformed order response');
		}
		return new CheckoutSession($approve, $id);
	}

	public function verifyWebhook(IRequest $request): VerifiedEvent
	{
		$payload = json_decode((string)$request->getContent(), true);
		if (!is_array($payload)) {
			throw new PspException('PayPal: malformed webhook payload');
		}
		$eventType = (string)($payload['event_type'] ?? '');
		$resource = $payload['resource'] ?? [];
		$ref = (string)($resource['id'] ?? '');
		if ($ref === '') {
			throw new PspException('PayPal: missing resource id');
		}
		// refetch the resource — the event body is never trusted
		$token = $this->oauthToken();
		if (str_starts_with($eventType, 'PAYMENT.CAPTURE')) {
			$r = $this->get($this->api() . '/v2/payments/captures/' . $ref,
				['Authorization' => 'Bearer ' . $token]);
			$status = strtoupper((string)($r['status'] ?? ''));
			$kind = match ($status) {
				'COMPLETED' => VerifiedEvent::KIND_PAID,
				'REFUNDED' => VerifiedEvent::KIND_REFUNDED,
				'FAILED', 'DENIED' => VerifiedEvent::KIND_FAILED,
				default => VerifiedEvent::KIND_PENDING,
			};
			$amountCents = isset($r['amount']['value'])
				? (int)round(((float)$r['amount']['value']) * 100) : null;
			return new VerifiedEvent(
				(string)($payload['id'] ?? ($ref . ':' . $status)),
				(string)($r['supplementary_data']['related_ids']['order_id'] ?? $ref),
				$kind, $amountCents, $r['amount']['currency_code'] ?? null, $status,
			);
		}
		$r = $this->get($this->api() . '/v2/checkout/orders/' . $ref,
			['Authorization' => 'Bearer ' . $token]);
		$status = strtoupper((string)($r['status'] ?? ''));
		$kind = match ($status) {
			'COMPLETED' => VerifiedEvent::KIND_PAID,
			'VOIDED' => VerifiedEvent::KIND_FAILED,
			default => VerifiedEvent::KIND_PENDING,
		};
		return new VerifiedEvent(
			(string)($payload['id'] ?? ($ref . ':' . $status)),
			$ref, $kind, null, null, $status,
		);
	}

	public function refund(string $pspRef, int $amountCents, string $currency): RefundResult
	{
		try {
			$token = $this->oauthToken();
			$res = $this->post($this->api() . '/v2/payments/captures/' . $pspRef . '/refund',
				['Authorization' => 'Bearer ' . $token],
				['amount' => [
					'currency_code' => $currency,
					'value' => number_format($amountCents / 100, 2, '.', ''),
				]]);
			return new RefundResult(true, (string)($res['id'] ?? ''));
		} catch (PspException $e) {
			return new RefundResult(false, '', $e->getMessage());
		}
	}

	public function testConnection(): bool
	{
		try {
			$this->oauthToken();
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	private function api(): string
	{
		return $this->settings->get('paypal_sandbox') === '1' ? self::API_SANDBOX : self::API;
	}

	private function oauthToken(): string
	{
		$id = $this->settings->get('paypal_client_id');
		$secret = $this->settings->getSecret('paypal_client_secret');
		if ($id === '' || $secret === '') {
			throw new PspException('PayPal is not configured');
		}
		try {
			$client = $this->clientService->newClient();
			$res = $client->post($this->api() . '/v1/oauth2/token', [
				'headers' => ['Authorization' => 'Basic ' . base64_encode($id . ':' . $secret),
					'Content-Type' => 'application/x-www-form-urlencoded'],
				'body' => 'grant_type=client_credentials',
				'timeout' => self::TIMEOUT,
			]);
			$data = json_decode($res->getBody(), true);
			$token = $data['access_token'] ?? '';
			if (!is_string($token) || $token === '') {
				throw new PspException('PayPal OAuth: no token');
			}
			return $token;
		} catch (PspException $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw new PspException('PayPal OAuth failed: ' . $e->getMessage(), 0, $e);
		}
	}
}

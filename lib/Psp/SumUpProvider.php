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
 * SumUp checkout (v0.1 API).
 *
 * Webhook model mirrors Mollie: SumUp notifies with a checkout id —
 * trust comes from the server-side refetch with the API key, never
 * from the POST body alone.
 */
class SumUpProvider extends AbstractProvider
{
	private const API = 'https://api.sumup.com/v0.1';

	public function __construct(
		IClientService $clientService,
		private readonly SettingsService $settings,
	) {
		parent::__construct($clientService);
	}

	public function id(): string
	{
		return 'sumup';
	}

	public function createCheckout(Order $order, string $returnUrl, string $webhookUrl): CheckoutSession
	{
		$key = $this->apiKey();
		$merchant = $this->settings->get('sumup_merchant_code');
		if ($key === '' || $merchant === '') {
			throw new PspException('SumUp is not configured');
		}
		$res = $this->post(self::API . '/checkouts', ['Authorization' => 'Bearer ' . $key], [
			'checkout_reference' => $order->getRef(),
			'amount' => (int)$order->getAmountCents() / 100,
			'currency' => $order->getCurrency(),
			'merchant_code' => $merchant,
			'description' => 'Order ' . $order->getRef(),
			'redirect_url' => $returnUrl,
		]);
		$id = $res['id'] ?? null;
		if (!is_string($id)) {
			throw new PspException('SumUp: malformed checkout response');
		}
		// SumUp hosted page is the public card widget URL per checkout id
		return new CheckoutSession('https://api.sumup.com/v0.1/checkouts/' . $id, $id);
	}

	public function verifyWebhook(IRequest $request): VerifiedEvent
	{
		$payload = json_decode((string)$request->getContent(), true);
		$id = is_array($payload) ? ($payload['id'] ?? $payload['checkout_id'] ?? null) : null;
		if (!is_string($id) || $id === '') {
			throw new PspException('SumUp: malformed webhook payload');
		}
		$co = $this->get(self::API . '/checkouts/' . $id,
			['Authorization' => 'Bearer ' . $this->apiKey()]);
		$status = strtoupper((string)($co['status'] ?? ''));
		$kind = match ($status) {
			'PAID' => VerifiedEvent::KIND_PAID,
			'FAILED' => VerifiedEvent::KIND_FAILED,
			default => VerifiedEvent::KIND_PENDING,
		};
		$amountCents = isset($co['amount']) ? (int)round(((float)$co['amount']) * 100) : null;
		return new VerifiedEvent(
			$id . ':' . $status, $id, $kind,
			$amountCents, $co['currency'] ?? null, $status,
		);
	}

	public function refund(string $pspRef, int $amountCents, string $currency): RefundResult
	{
		try {
			$res = $this->post(self::API . '/checkouts/' . $pspRef . '/refunds',
				['Authorization' => 'Bearer ' . $this->apiKey()],
				['amount' => $amountCents / 100]);
			return new RefundResult(true, (string)($res['id'] ?? ''));
		} catch (PspException $e) {
			return new RefundResult(false, '', $e->getMessage());
		}
	}

	public function testConnection(): bool
	{
		try {
			$merchant = $this->settings->get('sumup_merchant_code');
			$this->get(self::API . '/merchants/' . rawurlencode($merchant),
				['Authorization' => 'Bearer ' . $this->apiKey()]);
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	private function apiKey(): string
	{
		return $this->settings->getSecret('sumup_api_key');
	}
}

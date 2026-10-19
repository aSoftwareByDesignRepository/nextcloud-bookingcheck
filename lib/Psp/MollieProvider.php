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
 * Mollie hosted checkout (v2 API).
 *
 * Webhook model: Mollie POSTs only `id=tr_…` — unsigned by design. Trust
 * comes from the server-side refetch with the API key: a forged webhook
 * can at most cause a status lookup, never a fabricated "paid".
 */
class MollieProvider extends AbstractProvider
{
	private const API = 'https://api.mollie.com/v2';

	public function __construct(
		IClientService $clientService,
		private readonly SettingsService $settings,
	) {
		parent::__construct($clientService);
	}

	public function id(): string
	{
		return 'mollie';
	}

	public function createCheckout(Order $order, string $returnUrl, string $webhookUrl): CheckoutSession
	{
		$key = $this->apiKey((int)$order->getIsTest() === 1);
		if ($key === '') {
			throw new PspException('Mollie is not configured');
		}
		$res = $this->post(self::API . '/payments', ['Authorization' => 'Bearer ' . $key], [
			'amount' => [
				'currency' => $order->getCurrency(),
				'value' => number_format((int)$order->getAmountCents() / 100, 2, '.', ''),
			],
			'description' => 'Order ' . $order->getRef(),
			'redirectUrl' => $returnUrl,
			'webhookUrl' => $webhookUrl,
			'metadata' => ['order_id' => (int)$order->getId(), 'order_ref' => $order->getRef()],
		]);
		$checkout = $res['_links']['checkout']['href'] ?? null;
		$pspRef = $res['id'] ?? null;
		if (!is_string($checkout) || !is_string($pspRef)) {
			throw new PspException('Mollie: malformed create-payment response');
		}
		return new CheckoutSession($checkout, $pspRef);
	}

	public function verifyWebhook(IRequest $request): VerifiedEvent
	{
		$id = $request->getParam('id');
		if (!is_string($id) || !preg_match('/^tr_[A-Za-z0-9]+$/', $id)) {
			throw new PspException('Mollie: malformed webhook payload');
		}
		// Server-side refetch IS the verification (API key auth).
		$payment = $this->get(self::API . '/payments/' . $id,
			['Authorization' => 'Bearer ' . $this->apiKey(false)]);
		$status = (string)($payment['status'] ?? '');
		$kind = match ($status) {
			'paid' => VerifiedEvent::KIND_PAID,
			'failed', 'canceled', 'expired' => VerifiedEvent::KIND_FAILED,
			'refunded' => VerifiedEvent::KIND_REFUNDED,
			default => VerifiedEvent::KIND_PENDING,
		};
		$amountCents = isset($payment['amount']['value'])
			? (int)round(((float)$payment['amount']['value']) * 100) : null;
		return new VerifiedEvent(
			$id . ':' . $status, // dedupe per status transition
			$id,
			$kind,
			$amountCents,
			$payment['amount']['currency'] ?? null,
			$status,
		);
	}

	public function refund(string $pspRef, int $amountCents, string $currency): RefundResult
	{
		try {
			$res = $this->post(self::API . '/payments/' . $pspRef . '/refunds',
				['Authorization' => 'Bearer ' . $this->apiKey(false),
					// ignored where unsupported; a stored refund_ref on our
					// side is the actual dedupe (see OrderController::refund)
					'Idempotency-Key' => 'bck-refund-' . $pspRef . '-' . $amountCents,
				],
				['amount' => [
					'currency' => $currency,
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
			$this->get(self::API . '/methods', ['Authorization' => 'Bearer ' . $this->apiKey(false)]);
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	private function apiKey(bool $test): string
	{
		$key = $test ? $this->settings->getSecret('mollie_test_key') : $this->settings->getSecret('mollie_api_key');
		return $key !== '' ? $key : $this->settings->getSecret('mollie_api_key');
	}
}

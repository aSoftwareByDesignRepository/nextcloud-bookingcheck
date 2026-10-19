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
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * §9.13 sandbox adapter for end-to-end and CI testing — no live PSP keys needed.
 *
 * Safety properties:
 *  - Only reachable when an admin sets psp_provider=test via occ/app config —
 *    never offered in the admin settings UI.
 *  - Webhook truth still applies: createCheckout does NOT mark the order paid.
 *    The sandbox endpoint emits a signed payload the operator must POST to
 *    /psp/webhook/test — signature = HMAC-SHA256(rawBody, psp_test_secret).
 *  - createCheckout returns an internal sandbox page URL, never an external
 *    "auto-pay" link; completing payment always goes through the webhook path.
 */
class TestProvider implements PaymentProviderInterface
{
	public function __construct(
		private readonly SettingsService $settings,
		private readonly IURLGenerator $url,
	) {
	}

	public function id(): string
	{
		return 'test';
	}

	public function createCheckout(Order $order, string $returnUrl, string $webhookUrl): CheckoutSession
	{
		$ref = 'test_pay_' . bin2hex(random_bytes(8));
		$checkoutUrl = $this->url->linkToRouteAbsolute(
			'bookingcheck.pubSandbox.pay',
			['pspRef' => $ref],
		);
		return new CheckoutSession($checkoutUrl, $ref);
	}

	public function verifyWebhook(IRequest $request): VerifiedEvent
	{
		return $this->verifyRaw(
			(string)file_get_contents('php://input'),
			$request->getHeader('X-Bck-Signature'),
		);
	}

	/** Verify + normalize a raw signed body — the unit-testable seam. */
	public function verifyRaw(string $raw, string $sig): VerifiedEvent
	{
		$secret = $this->settings->get('psp_test_secret');
		if ($secret === '') {
			throw new PspException('Sandbox secret not configured');
		}
		if ($sig === '' || !hash_equals(hash_hmac('sha256', $raw, $secret), $sig)) {
			throw new PspException('Bad sandbox signature');
		}
		$body = json_decode($raw, true);
		if (!is_array($body) || !isset($body['id'], $body['psp_ref'], $body['status'])) {
			throw new PspException('Malformed sandbox event');
		}
		$kind = match ($body['status']) {
			'paid' => VerifiedEvent::KIND_PAID,
			'failed', 'canceled' => VerifiedEvent::KIND_FAILED,
			'refunded' => VerifiedEvent::KIND_REFUNDED,
			default => VerifiedEvent::KIND_PENDING,
		};
		return new VerifiedEvent(
			(string)$body['id'],
			(string)$body['psp_ref'],
			$kind,
			isset($body['amount_cents']) ? (int)$body['amount_cents'] : null,
			isset($body['currency']) ? (string)$body['currency'] : null,
			(string)$body['status'],
		);
	}

	public function refund(string $pspRef, int $amountCents, string $currency): RefundResult
	{
		return new RefundResult(true, 'test_ref_' . bin2hex(random_bytes(6)));
	}

	public function testConnection(): bool
	{
		return $this->settings->get('psp_test_secret') !== '';
	}
}

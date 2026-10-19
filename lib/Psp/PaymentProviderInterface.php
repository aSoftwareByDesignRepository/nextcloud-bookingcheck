<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Psp;

use OCA\BookingCheck\Db\Order;
use OCP\IRequest;

/**
 * §9.7 adapter contract. Order code never talks PSP directly.
 * Implementations: MollieProvider, StripeProvider (both hosted-checkout / SAQ-A).
 */
interface PaymentProviderInterface
{
	public function id(): string; // 'mollie' | 'stripe'

	/** Create a hosted checkout session; buyer is redirected to checkoutUrl. */
	public function createCheckout(Order $order, string $returnUrl, string $webhookUrl): CheckoutSession;

	/**
	 * Verify an inbound webhook and normalize it.
	 * MUST authenticate the event cryptographically or by server-side
	 * refetch before returning — a forged POST must never produce
	 * a trusted event.
	 *
	 * @throws \OCA\BookingCheck\Exception\PspException on unverifiable input
	 */
	public function verifyWebhook(IRequest $request): VerifiedEvent;

	/** Refund (full or partial). Idempotent on the PSP side where supported. */
	public function refund(string $pspRef, int $amountCents, string $currency): RefundResult;

	public function testConnection(): bool;
}

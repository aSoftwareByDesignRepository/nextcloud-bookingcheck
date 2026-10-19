<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Psp;

use OCA\BookingCheck\Exception\PspException;
use OCA\BookingCheck\Service\SettingsService;

/**
 * Resolves the PSP adapter for a checkout or a webhook.
 *
 * Webhook routing uses the provider RECORDED on bc_payment — an admin
 * switching PSP mid-operations must not strand in-flight orders.
 */
class PspManager
{
	public function __construct(
		private readonly MollieProvider $mollie,
		private readonly StripeProvider $stripe,
		private readonly TestProvider $test,
		private readonly SettingsService $settings,
	) {
	}

	public function forCheckout(): PaymentProviderInterface
	{
		return $this->byName($this->settings->get('psp_provider'));
	}

	public function byName(string $name): PaymentProviderInterface
	{
		return match ($name) {
			'mollie' => $this->mollie,
			'stripe' => $this->stripe,
			'test' => $this->test, // sandbox — admin-configured only, §9.13

			default => throw new PspException('No payment provider configured'),
		};
	}
}

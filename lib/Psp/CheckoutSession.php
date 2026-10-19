<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Psp;

final class CheckoutSession
{
	public function __construct(
		public readonly string $checkoutUrl,
		public readonly string $pspRef,
	) {
	}
}

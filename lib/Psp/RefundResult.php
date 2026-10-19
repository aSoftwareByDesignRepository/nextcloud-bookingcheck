<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Psp;

final class RefundResult
{
	public function __construct(
		public readonly bool $ok,
		public readonly string $refundRef = '',
		public readonly string $error = '',
	) {
	}
}

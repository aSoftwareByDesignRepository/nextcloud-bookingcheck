<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Psp;

final class VerifiedEvent
{
	public const KIND_PAID = 'paid';
	public const KIND_FAILED = 'failed';
	public const KIND_REFUNDED = 'refunded';
	public const KIND_DISPUTE = 'dispute';
	public const KIND_PENDING = 'pending'; // known-but-not-final → ack only

	public function __construct(
		public readonly string $eventId,     // provider-unique event/payment id (dedupe key)
		public readonly string $pspRef,      // payment/session ref we stored on bc_payment
		public readonly string $kind,
		public readonly ?int $amountCents = null,
		public readonly ?string $currency = null,
		public readonly string $rawStatus = '',
	) {
	}
}

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

/**
 * Transactional email bodies — one obvious action per email.
 * Header-interpolated values are CRLF-stripped (§9.9).
 */
class MailRenderer
{
	/** @return array{0:string,1:string} subject + plaintext body */
	public function render(string $kind, array $p, string $locale): array
	{
		$ref = self::head($p['order_ref'] ?? '');
		return match ($kind) {
			'order_confirmed' => [
				'Booking confirmed — ' . $ref,
				"Your booking is confirmed.\n\nOrder: {$ref}\n\n" .
					"Manage your booking (cancel, downloads, calendar): " .
					self::head($p['manage_url'] ?? '') . "\n\n" .
					"Your tickets are attached to this email.\n",
			],
			'payment_link' => [
				'Your booking was approved — complete payment — ' . $ref,
				"Good news — your booking request was approved.\n\nComplete payment here: " .
					self::head($p['pay_url'] ?? '') . "\n",
			],
			'payment_failed' => [
				'Payment did not complete — ' . $ref,
				"Your payment for order {$ref} did not complete. The seats were released.\nYou can book again any time.\n",
			],
			'order_expired' => [
				'Booking expired — ' . $ref,
				"Order {$ref} expired because payment was not completed in time.\n",
			],
			'order_declined' => [
				'Booking request declined — ' . $ref,
				"Unfortunately your booking request {$ref} was declined. No charge was made.\n",
			],
			'approval_request' => [
				'New booking request — ' . $ref,
				"A new booking request {$ref} needs your approval.\nOpen BookingCheck → Orders.\n",
			],
			'approval_pending' => [
				'Booking request received — ' . $ref,
				"We received your booking request {$ref} — you'll hear from us shortly.\n",
			],
			'otp' => [
				'Your sign-in code',
				"Your code: " . self::head($p['code'] ?? '') .
					"\n\nOr sign in directly: " . self::head($p['magic_url'] ?? '') . "\n",
			],
			'waitlist_offer' => [
				'A seat opened up',
				"A seat is held for you — claim it here: " .
					self::head($p['claim_url'] ?? '') . "\n",
			],
			'manage_link' => [
				'Your booking link — ' . $ref,
				"Here is your manage link for order {$ref}:\n" .
					self::head($p['manage_url'] ?? '') . "\n",
			],
			'broadcast' => [
				self::head($p['subject'] ?? 'Update'),
				// seller-authored plaintext — no HTML interpretation, ever
				(string)($p['body'] ?? '') . "\n",
			],
			'seller_order_paid' => [
				'New paid booking — ' . $ref,
				"Order {$ref} was paid.\n" .
					(!empty($p['offering']) ? 'Offering: ' . self::head((string)$p['offering']) . "\n" : '') .
					(isset($p['amount']) ? 'Amount: ' . number_format((float)$p['amount'], 2) . ' ' .
						self::head((string)($p['currency'] ?? '')) . "\n" : '') .
					"Open BookingCheck → Orders.\n",
			],
			default => [
				'BookingCheck',
				"Notification for order {$ref}.\n",
			],
		};
	}

	/** Strip CR/LF from header-bound values (§9.9). */
	private static function head(string $v): string
	{
		return str_replace(["\r", "\n"], '', $v);
	}
}

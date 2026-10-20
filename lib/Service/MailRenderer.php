<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCP\L10N\IFactory;

/**
 * Transactional email bodies — one obvious action per email.
 * Header-interpolated values are CRLF-stripped (§9.9).
 * Strings resolve in the BUYER's locale (the $locale the mail was
 * queued with) — never the seller's or the instance default.
 */
class MailRenderer
{
	public function __construct(
		private readonly IFactory $l10nFactory,
	) {
	}

	/** @return array{0:string,1:string} subject + plaintext body */
	public function render(string $kind, array $p, string $locale): array
	{
		$l = $this->l10nFactory->get('bookingcheck', $locale);
		$ref = self::head($p['order_ref'] ?? '');
		return match ($kind) {
			'order_confirmed' => [
				$l->t('Booking confirmed — %s', [$ref]),
				$l->t("Your booking is confirmed.\n\nOrder: %s\n\n"
					. "Manage your booking (cancel, downloads, calendar): %s\n\n"
					. "Your tickets are attached to this email.\n",
					[$ref, self::head($p['manage_url'] ?? '')]),
			],
			'payment_link' => [
				$l->t('Your booking was approved — complete payment — %s', [$ref]),
				$l->t("Good news — your booking request was approved.\n\nComplete payment here: %s\n",
					[self::head($p['pay_url'] ?? '')]),
			],
			'payment_failed' => [
				$l->t('Payment did not complete — %s', [$ref]),
				$l->t("Your payment for order %s did not complete. The seats were released.\nYou can book again any time.\n",
					[$ref]),
			],
			'order_expired' => [
				$l->t('Booking expired — %s', [$ref]),
				$l->t("Order %s expired because payment was not completed in time.\n", [$ref]),
			],
			'order_declined' => [
				$l->t('Booking request declined — %s', [$ref]),
				$l->t("Unfortunately your booking request %s was declined. No charge was made.\n", [$ref]),
			],
			'approval_request' => [
				$l->t('New booking request — %s', [$ref]),
				$l->t("A new booking request %s needs your approval.\nOpen BookingCheck → Orders.\n",
					[$ref]),
			],
			'approval_pending' => [
				$l->t('Booking request received — %s', [$ref]),
				$l->t("We received your booking request %s — you'll hear from us shortly.\n", [$ref]),
			],
			'otp' => [
				$l->t('Your sign-in code'),
				$l->t("Your code: %s\n\nOr sign in directly: %s\n",
					[self::head($p['code'] ?? ''), self::head($p['magic_url'] ?? '')]),
			],
			'waitlist_offer' => [
				$l->t('A seat opened up'),
				$l->t("A seat is held for you — claim it here: %s\n",
					[self::head($p['claim_url'] ?? '')]),
			],
			'waitlist_notify' => [
				!empty($p['title'])
					? $l->t('A seat opened up — %s', [self::head((string)$p['title'])])
					: $l->t('A seat opened up'),
				$l->t("A seat just became available — first to book gets it.\n\nBook here: %s\n",
					[self::head($p['offering_url'] ?? '')]),
			],
			'manage_link' => [
				$l->t('Your booking link — %s', [$ref]),
				$l->t("Here is your manage link for order %s:\n%s\n",
					[$ref, self::head($p['manage_url'] ?? '')]),
			],
			'broadcast' => [
				self::head($p['subject'] ?? $l->t('Update')),
				// seller-authored plaintext — no HTML interpretation, ever
				(string)($p['body'] ?? '') . "\n",
			],
			'rescheduled' => [
				$l->t('Your booking moved to a new time — %s', [$ref]),
				$l->t("The time of your booking changed.\n")
					. (!empty($p['title']) ? $l->t("Offering: %s\n", [self::head((string)$p['title'])]) : '')
					. $l->t("New time: %s – %s\n", [
						self::head((string)($p['new_start'] ?? '')),
						self::head((string)($p['new_end'] ?? ''))])
					. $l->t("Order: %s\n", [$ref]),
			],
			'seller_order_paid' => [
				$l->t('New paid booking — %s', [$ref]),
				$l->t("Order %s was paid.\n", [$ref])
					. (!empty($p['offering']) ? $l->t("Offering: %s\n", [self::head((string)$p['offering'])]) : '')
					. (isset($p['amount']) ? $l->t("Amount: %s %s\n", [
						number_format((float)$p['amount'], 2),
						self::head((string)($p['currency'] ?? ''))]) : '')
					. $l->t("Open BookingCheck → Orders.\n"),
			],
			default => [
				$l->t('BookingCheck'),
				$l->t("Notification for order %s.\n", [$ref]),
			],
		};
	}

	/** Strip CR/LF from header-bound values (§9.9). */
	private static function head(string $v): string
	{
		return str_replace(["\r", "\n"], '', $v);
	}
}

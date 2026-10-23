<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Support;

use OCP\IL10N;

/**
 * Order status → human label + badge variant. Templates must never echo
 * the raw enum (`pending_payment` is a wire value, not copy) — badges
 * carry a translated label (DESIGN-SYSTEM: no raw enum strings).
 */
final class StatusLabels
{
	/** Valid order statuses — filter params outside this set fail closed. */
	public const STATUSES = [
		'created' => true, 'pending_payment' => true, 'pending_approval' => true,
		'approved' => true, 'paid' => true, 'cancelled' => true,
		'refunded' => true, 'failed' => true, 'expired' => true, 'declined' => true,
	];

	private const LABELS = [
		'created' => 'Created',
		'pending_payment' => 'Pending payment',
		'pending_approval' => 'Pending approval',
		'approved' => 'Approved',
		'paid' => 'Paid',
		'cancelled' => 'Cancelled',
		'refunded' => 'Refunded',
		'failed' => 'Failed',
		'expired' => 'Expired',
		'declined' => 'Declined',
	];

	private const BADGES = [
		'created' => 'neutral',
		'pending_payment' => 'warn',
		'pending_approval' => 'info',
		'approved' => 'info',
		'paid' => 'success',
		'cancelled' => 'neutral',
		'refunded' => 'neutral',
		'failed' => 'danger',
		'expired' => 'neutral',
		'declined' => 'neutral',
	];

	/** PSP payment states — different vocabulary than order states. */
	private const PAYMENT_LABELS = [
		'open' => 'Open',
		'authorized' => 'Authorized',
		'paid' => 'Paid',
		'failed' => 'Failed',
		'cancelled' => 'Cancelled',
		'expired' => 'Expired',
		'refunded' => 'Refunded',
		'charged_back' => 'Charged back',
	];

	/** Offering lifecycle states. */
	private const OFFERING_LABELS = [
		'draft' => 'Draft',
		'published' => 'Published',
		'paused' => 'Paused',
		'archived' => 'Archived',
	];

	/** Waitlist entry states (bc_waitlist.state). */
	private const WAITLIST_LABELS = [
		'waiting' => 'Waiting',
		'offered' => 'Offered',
		'claimed' => 'Claimed',
		'expired' => 'Expired',
		'declined' => 'Declined',
	];

	/** Review moderation states (bc_review.status). */
	private const REVIEW_LABELS = [
		'pending' => 'Pending',
		'published' => 'Published',
		'hidden' => 'Hidden',
	];

	public static function label(IL10N $l, string $status): string
	{
		return isset(self::LABELS[$status]) ? $l->t(self::LABELS[$status]) : $status;
	}

	public static function badge(string $status): string
	{
		return self::BADGES[$status] ?? 'neutral';
	}

	public static function offeringStatus(IL10N $l, string $status): string
	{
		return isset(self::OFFERING_LABELS[$status]) ? $l->t(self::OFFERING_LABELS[$status]) : $status;
	}

	public static function waitlistState(IL10N $l, string $state): string
	{
		return isset(self::WAITLIST_LABELS[$state]) ? $l->t(self::WAITLIST_LABELS[$state]) : $state;
	}

	public static function reviewState(IL10N $l, string $state): string
	{
		return isset(self::REVIEW_LABELS[$state]) ? $l->t(self::REVIEW_LABELS[$state]) : $state;
	}

	public static function payment(IL10N $l, string $status): string
	{
		return isset(self::PAYMENT_LABELS[$status]) ? $l->t(self::PAYMENT_LABELS[$status]) : $status;
	}
}

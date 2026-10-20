<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\License\Bck2Codec;
use OCA\BookingCheck\Service\SettingsService;

/**
 * Pro gating. A stored BCK2 key that verifies AND is in-date unlocks Pro.
 * Free tier keeps the full booking spine; Pro unlocks the named extras.
 * Gating is server-side only — a hidden UI button is not a gate.
 */
class LicenseService
{
	/** Pro-gated features (§ licensing). Free tier: catalog, checkout,
	 *  PSP payment, invoices, tickets, manage links, orders, roster/scan. */
	public const PRO_FEATURES = [
		'vouchers', 'promos', 'waitlist', 'portal', 'embed', 'directory_feed',
		'outbound_webhooks', 'materials', 'uploads', 'reviews', 'b2b_vies',
		'resources', 'intake_questions', 'broadcasts', 'customercheck_sync',
		'companion', 'reports_csv', 'passes',
	];

	private const STATE_FREE = 'free';
	private const STATE_PRO = 'pro';
	private const STATE_EXPIRED = 'expired';
	private const STATE_INVALID = 'invalid';

	public function __construct(
		private readonly SettingsService $settings,
	) {
	}

	/** @return array{state:string, validUntil:?string, proSeats:int, error:string} */
	public function status(): array
	{
		$wire = $this->settings->get('license_key');
		if ($wire === '') {
			return ['state' => self::STATE_FREE, 'validUntil' => null, 'proSeats' => 0, 'error' => ''];
		}
		$err = Bck2Codec::classifyError($wire);
		if ($err !== '') {
			return ['state' => self::STATE_INVALID, 'validUntil' => null, 'proSeats' => 0, 'error' => $err];
		}
		$decoded = Bck2Codec::parseAndVerify($wire);
		if ($decoded === null) {
			return ['state' => self::STATE_INVALID, 'validUntil' => null, 'proSeats' => 0, 'error' => Bck2Codec::ERROR_INVALID_PAYLOAD];
		}
		$p = $decoded['payload'];
		$validUntil = (string)$p['validUntil'];
		$today = (new \DateTimeImmutable('today'))->format('Y-m-d');
		if (!Bck2Codec::isValidOn($validUntil, $today)) {
			return ['state' => self::STATE_EXPIRED, 'validUntil' => $validUntil, 'proSeats' => (int)$p['proSeats'], 'error' => 'expired'];
		}
		return ['state' => self::STATE_PRO, 'validUntil' => $validUntil, 'proSeats' => (int)$p['proSeats'], 'error' => ''];
	}

	public function isPro(): bool
	{
		return $this->status()['state'] === self::STATE_PRO;
	}

	public function hasFeature(string $feature): bool
	{
		if (!in_array($feature, self::PRO_FEATURES, true)) {
			return true; // unknown feature = not gated (free path)
		}
		if ($this->isPro()) {
			return true;
		}
		// Buyer protection (spec §License gating): portal READ access
		// persists 90 days past expiry — attendees keep tickets/materials
		// while seller-side Pro controls disable immediately.
		if ($feature === 'portal') {
			$s = $this->status();
			if ($s['state'] === self::STATE_EXPIRED && $s['validUntil'] !== null) {
				$graceEnd = (new \DateTimeImmutable($s['validUntil']))->modify('+90 days');
				return $graceEnd >= new \DateTimeImmutable('today');
			}
		}
		return false;
	}

	/** Throw when a Pro-gated feature is invoked on the free tier. */
	public function requireFeature(string $feature): void
	{
		if (!$this->hasFeature($feature)) {
			throw new \OCA\BookingCheck\Exception\ProRequiredException($feature);
		}
	}
}

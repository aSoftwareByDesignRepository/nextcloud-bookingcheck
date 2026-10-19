<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\Db\AddonMapper;
use OCA\BookingCheck\Db\PriceCatMapper;
use OCA\BookingCheck\Db\PromoMapper;
use OCA\BookingCheck\Db\PromoScopeMapper;
use OCA\BookingCheck\Db\VoucherMapper;
use OCA\BookingCheck\Exception\ValidationException;

/**
 * Server-side price computation. The client submits STRUCTURE (which
 * targets, categories, quantities); every cent comes from DB rows.
 * A tampered price_cat_id or addon id fails at FK-ownership validation,
 * never at arithmetic.
 */
class PricingService
{
	public function __construct(
		private readonly PriceCatMapper $priceCats,
		private readonly AddonMapper $addons,
		private readonly PromoMapper $promos,
		private readonly PromoScopeMapper $promoScopes,
		private readonly VoucherMapper $vouchers,
	) {
	}

	/**
	 * Price an order draft.
	 *
	 * @param array $offering  offering row (id, price_cents, currency, vat_bp)
	 * @param list<array{target_type:string,target_id:int,seats:int,price_cat_id?:int,resource_id?:int}> $seatLines
	 * @param list<array{addon_id:int,qty:int}> $addonLines
	 * @param string|null $promoCode
	 * @param string|null $voucherCode
	 * @return array{
	 *   lines: list<array>, seatSubtotal:int, addonSubtotal:int,
	 *   promoCents:int, promoId:?int, promoCode:?string,
	 *   voucherId:?int, voucherCents:int, total:int,
	 *   netCents:int, vatCents:int, vatBp:int, currency:string
	 * }
	 */
	public function price(array $offering, array $seatLines, array $addonLines = [], ?string $promoCode = null, ?string $voucherCode = null): array
	{
		$offeringId = (int)$offering['id'];
		$fields = [];

		$lines = [];
		$seatSubtotal = 0;

		// ---- seat lines -------------------------------------------------
		foreach ($seatLines as $i => $l) {
			$seats = (int)($l['seats'] ?? 0);
			if ($seats < 1 || $seats > 50) {
				$fields["lines.$i.seats"] = 'Invalid seat count.';
				continue;
			}
			$unit = (int)$offering['price_cents'];
			$catId = null;
			$catName = null;
			if (!empty($l['price_cat_id'])) {
				$cat = $this->priceCats->find((int)$l['price_cat_id']);
				if ($cat === null || (int)$cat->getOfferingId() !== $offeringId || (int)$cat->getActive() !== 1) {
					$fields["lines.$i.price_cat_id"] = 'Unknown price category.';
					continue;
				}
				if ($cat->getMaxPerOrder() !== null && $seats > (int)$cat->getMaxPerOrder()) {
					$fields["lines.$i.seats"] = 'Too many seats for this category.';
					continue;
				}
				$catId = (int)$cat->getId();
				$catName = $cat->getName();
				$unit = (int)$cat->getPriceCents();
			}
			$lineCents = $unit * $seats;
			$seatSubtotal += $lineCents;
			$lines[] = [
				'line_kind' => 'seat', 'target_type' => $l['target_type'],
				'target_id' => (int)$l['target_id'], 'resource_id' => $l['resource_id'] ?? null,
				'price_cat_id' => $catId, 'cat_name' => $catName,
				'seats' => $seats, 'unit_cents' => $unit, 'line_cents' => $lineCents,
			];
		}

		// ---- addon lines ------------------------------------------------
		$addonSubtotal = 0;
		foreach ($addonLines as $i => $a) {
			$qty = (int)($a['qty'] ?? 0);
			$addon = $this->addons->find((int)($a['addon_id'] ?? 0));
			if ($addon === null || (int)$addon->getOfferingId() !== $offeringId || (int)$addon->getActive() !== 1) {
				$fields["addons.$i"] = 'Unknown add-on.';
				continue;
			}
			if ($qty < 1) {
				continue;
			}
			if ($addon->getMaxPerOrder() !== null && $qty > (int)$addon->getMaxPerOrder()) {
				$fields["addons.$i"] = 'Too many for this add-on.';
				continue;
			}
			$lineCents = (int)$addon->getPriceCents() * $qty;
			$addonSubtotal += $lineCents;
			$lines[] = [
				'line_kind' => 'addon', 'target_type' => $seatLines[0]['target_type'] ?? 'event',
				'target_id' => (int)($seatLines[0]['target_id'] ?? 0),
				'resource_id' => null, 'price_cat_id' => null,
				'cat_name' => $addon->getName(),
				'seats' => $qty, 'unit_cents' => (int)$addon->getPriceCents(),
				'line_cents' => $lineCents,
			];
		}

		if ($fields !== []) {
			throw new ValidationException($fields);
		}
		if ($lines === []) {
			throw new ValidationException(['lines' => 'Nothing to book.']);
		}

		// ---- promo (eligible: seat lines only, never addons/vouchers) ---
		$promoCents = 0;
		$promoId = null;
		$promoRow = null;
		if ($promoCode !== null && $promoCode !== '') {
			$promoRow = $this->promos->findByCode($promoCode);
			if ($promoRow !== null && $this->promoUsable($promoRow, $offeringId)) {
				$promoId = (int)$promoRow->getId();
				$eligible = $seatSubtotal;
				if ($promoRow->getKind() === 'percent') {
					// round-half-up per seat line, not on subtotal — line-exact credit
					foreach ($lines as $l) {
						if ($l['line_kind'] === 'seat') {
							$promoCents += intdiv($l['line_cents'] * (int)$promoRow->getPercentBps() + 5000, 10000);
						}
					}
				} else {
					$promoCents = min((int)$promoRow->getAmountCents(), $eligible);
				}
				$promoCents = min($promoCents, $eligible);
			}
			// invalid/expired code → silently ignored at quote; rejected on redeem
		}

		// ---- voucher (pays remainder; a payment method, not a discount) -
		$voucherId = null;
		$voucherCents = 0;
		if ($voucherCode !== null && $voucherCode !== '') {
			$v = $this->vouchers->findByCode($voucherCode);
			if ($v !== null && (int)$v->getActive() === 1
				&& (int)$v->getRemainingCents() > 0
				&& (int)$v->getUses() < (int)$v->getMaxUses()
				&& ($v->getValidUntil() === null || $v->getValidUntil() >= new \DateTimeImmutable())) {
				$voucherId = (int)$v->getId();
				$voucherCents = min((int)$v->getRemainingCents(), $seatSubtotal + $addonSubtotal - $promoCents);
			}
		}

		$total = max(0, $seatSubtotal + $addonSubtotal - $promoCents - $voucherCents);

		// ---- VAT split (gross-inclusive; §19 → 0; reverse charge → 0) ---
		$vatBp = (int)$offering['vat_bp'];
		[$net, $vat] = self::splitVat($total, $vatBp);

		return [
			'lines' => $lines,
			'seatSubtotal' => $seatSubtotal,
			'addonSubtotal' => $addonSubtotal,
			'promoCents' => $promoCents,
			'promoId' => $promoId,
			'promoCode' => $promoId !== null ? $promoCode : null,
			'promo' => $promoRow,
			'voucherId' => $voucherId,
			'voucherCents' => $voucherCents,
			'total' => $total,
			'netCents' => $net,
			'vatCents' => $vat,
			'vatBp' => $vatBp,
			'currency' => (string)$offering['currency'],
		];
	}

	/**
	 * Gross-inclusive VAT split. Returns [net, vat] in cents.
	 * Half-up per document total (GoBD: one VAT line per rate).
	 */
	public static function splitVat(int $grossCents, int $vatBp): array
	{
		if ($grossCents <= 0 || $vatBp <= 0) {
			return [$grossCents, 0];
		}
		$net = intdiv($grossCents * 10000 + (10000 + $vatBp) / 2, 10000 + $vatBp);
		return [$net, $grossCents - $net];
	}

	private function promoUsable($promo, int $offeringId): bool
	{
		if ((int)$promo->getActive() !== 1) {
			return false;
		}
		$now = new \DateTimeImmutable();
		if ($promo->getValidFrom() !== null && $promo->getValidFrom() > $now) {
			return false;
		}
		if ($promo->getValidUntil() !== null && $promo->getValidUntil() < $now) {
			return false;
		}
		if ($promo->getMaxUses() !== null && (int)$promo->getUses() >= (int)$promo->getMaxUses()) {
			return false;
		}
		if ($promo->getScope() === 'offerings' && !$this->promoScopes->inScope((int)$promo->getId(), $offeringId)) {
			return false;
		}
		return true;
	}
}

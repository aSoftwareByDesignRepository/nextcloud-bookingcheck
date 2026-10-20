<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\Db\AddonMapper;
use OCA\BookingCheck\Db\PriceCatMapper;
use OCA\BookingCheck\Db\Promo;
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
		private readonly LicenseService $license,
		private readonly \OCA\BookingCheck\Db\EventMapper $events,
		private readonly \OCP\IDBConnection $db,
		private readonly SettingsService $settings,
	) {
	}

	/**
	 * Price an order draft.
	 *
	 * @param array{id:int,price_cents:int,currency:string,vat_bp:int,dyn_bp?:int,dyn_step_pct?:int} $offering
	 * @param list<array{target_type:string,target_id:int,seats:int,price_cat_id?:int|null,resource_id?:int|null}> $seatLines
	 * @param list<array{addon_id:int,qty:int}> $addonLines
	 * @param string|null $promoCode
	 * @param string|null $voucherCode
	 * @return array{
	 *   lines: list<array>, seatSubtotal:int, addonSubtotal:int,
	 *   promoCents:int, promoId:?int, promoCode:?string, promo:?Promo,
	 *   voucherId:?int, voucherCents:int, total:int,
	 *   netCents:int, vatCents:int, vatBp:int, currency:string
	 * }
	 */
	public function price(array $offering, array $seatLines, array $addonLines = [], ?string $promoCode = null, ?string $voucherCode = null, bool $reverseCharge = false, ?string $billingCountry = null, ?int $passQty = null): array
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
				// NH-23 early-bird: a windowed category sells only inside
				// its sale window — a stale price must never be honored
				$now = new \DateTimeImmutable();
				if (($cat->getSaleFrom() !== null && $cat->getSaleFrom() > $now)
					|| ($cat->getSaleTo() !== null && $cat->getSaleTo() < $now)) {
					$fields["lines.$i.price_cat_id"] = 'This price category is not on sale now.';
					continue;
				}
				if ($cat->getMaxPerOrder() !== null && $seats > (int)$cat->getMaxPerOrder()) {
					$fields["lines.$i.seats"] = 'Too many seats for this category.';
					continue;
				}
				$catId = (int)$cat->getId();
				$catName = $cat->getName();
				$unit = (int)$cat->getPriceCents();
			} elseif (($l['target_type'] ?? '') === 'event') {
				// NH-07 per-occurrence pricing — the event row overrides
				// the offering base only when no category was picked
				$ev = $this->events->find((int)$l['target_id']);
				if ($ev !== null && $ev->getPriceCents() !== null) {
					$unit = (int)$ev->getPriceCents();
				}
			}
			// NH-25 dynamic pricing — server-computed markup on the fill
			// ratio of THIS target; never client-influenced
			$unit = $this->dynamicPrice($offering, $unit, (string)$l['target_type'], (int)$l['target_id']);
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

		// ---- pass line (NH-28): a 'pass' offering sells flat-price
		// memberships — no target, no inventory, qty = passes minted. ----
		if ($passQty !== null) {
			if ($passQty < 1 || $passQty > 50) {
				$fields['pass_qty'] = 'Invalid quantity.';
			} else {
				$unit = (int)$offering['price_cents'];
				$seatSubtotal += $unit * $passQty;
				$lines[] = [
					'line_kind' => 'pass', 'target_type' => 'offering',
					'target_id' => $offeringId, 'resource_id' => null,
					'price_cat_id' => null, 'cat_name' => null,
					'seats' => $passQty, 'unit_cents' => $unit,
					'line_cents' => $unit * $passQty,
				];
			}
		}

		if ($fields !== []) {
			throw new ValidationException($fields);
		}
		if ($lines === []) {
			throw new ValidationException(['lines' => 'Nothing to book.']);
		}

		// ---- promo (eligible: seat lines only, never addons/vouchers) ---
		// Pro-gated: on the free tier a submitted code is an unknown code —
		// silently ignored here, rejected at redeem like any other bad code.
		$promoCents = 0;
		$promoId = null;
		$promoRow = null;
		if ($promoCode !== null && $promoCode !== '' && $this->license->hasFeature('promos')) {
			$promoRow = $this->promos->findByCode($promoCode);
			if ($promoRow !== null && $this->promoUsable($promoRow, $offeringId)
				// a fixed-amount code is denominated — foreign-currency
				// offerings never see the discount (NULL = legacy row)
				&& ($promoRow->getCurrency() === null
					|| $promoRow->getKind() === 'percent'
					|| $promoRow->getCurrency() === (string)$offering['currency'])) {
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

		// ---- the charge basis the payment method covers ---------------
		// §13b reverse-charge buyers owe the NET of the remainder, so their
		// voucher credit is net-denominated: burning it against the gross
		// remainder would over-charge them by the VAT fraction.
		$vatBp = $this->vatBpFor($offering, $reverseCharge, $billingCountry);
		$grossRemainder = $seatSubtotal + $addonSubtotal - $promoCents;
		[$chargeBasis] = $reverseCharge ? self::splitVat($grossRemainder, $vatBp)
			: [$grossRemainder];

		// ---- voucher (pays the charge; a payment method, not a discount)
		$voucherId = null;
		$voucherCents = 0;
		if ($voucherCode !== null && $voucherCode !== '' && $this->license->hasFeature('vouchers')) {
			$v = $this->vouchers->findByCode($voucherCode);
			if ($v !== null && (int)$v->getActive() === 1
				&& (int)$v->getRemainingCents() > 0
				&& (int)$v->getUses() < (int)$v->getMaxUses()
				// denominated credit — foreign-currency offerings can't draw
				// on it (NULL = legacy row minted before the column existed)
				&& ($v->getCurrency() === null
					|| $v->getCurrency() === (string)$offering['currency'])
				&& ($v->getValidUntil() === null || $v->getValidUntil() >= new \DateTimeImmutable())) {
				$voucherId = (int)$v->getId();
				$voucherCents = min((int)$v->getRemainingCents(), $chargeBasis);
			}
		}

		$total = max(0, $chargeBasis - $voucherCents);

		// ---- VAT split (gross-inclusive; §19 → 0; reverse charge → 0) ---
		if ($reverseCharge) {
			// B2B cross-border: catalog prices are gross-inclusive, but a
			// reverse-charge buyer pays the NET and self-accounts VAT.
			[$net, $vat] = [$total, 0];
		} else {
			[$net, $vat] = self::splitVat($total, $vatBp);
		}

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
	 * @return array{0:int,1:int}
	 */
	public static function splitVat(int $grossCents, int $vatBp): array
	{
		if ($grossCents <= 0 || $vatBp <= 0) {
			return [$grossCents, 0];
		}
		// intdiv for the half: `(x)/2` on an odd operand yields a float,
		// which strict_types rejects as an intdiv argument (TypeError).
		$net = intdiv($grossCents * 10000 + intdiv(10000 + $vatBp, 2), 10000 + $vatBp);
		return [$net, $grossCents - $net];
	}

	/**
	 * NH-25 — price rises as the target fills. dyn_step_pct % of capacity
	 * consumed adds dyn_bp basis points to the unit price (cap 10 steps).
	 * Returns $unit unchanged when the feature is off or data is missing.
	 */
	/** @param array{dyn_bp?:int,dyn_step_pct?:int} $offering */
	private function dynamicPrice(array $offering, int $unit, string $targetType, int $targetId): int
	{
		$dynBp = (int)($offering['dyn_bp'] ?? 0);
		$stepPct = (int)($offering['dyn_step_pct'] ?? 0);
		if ($dynBp <= 0 || $stepPct <= 0 || $unit <= 0) {
			return $unit;
		}
		[$capacity, $used] = $this->targetFill($targetType, $targetId);
		if ($capacity <= 0) {
			return $unit;
		}
		$fillPct = (int)floor($used * 100 / $capacity);
		$steps = min(10, intdiv($fillPct, $stepPct));
		if ($steps <= 0) {
			return $unit;
		}
		return (int)round($unit * (10000 + $dynBp * $steps) / 10000);
	}

	/** @return array{0:int,1:int} capacity, sold+held for the target */
	private function targetFill(string $targetType, int $targetId): array
	{
		$table = match ($targetType) {
			'event' => 'bc_event', 'slot' => 'bc_slot', 'series' => 'bc_series',
			default => null,
		};
		if ($table === null) {
			return [0, 0];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('capacity', 'sold', 'held')->from($table)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($targetId)));
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			return [0, 0];
		}
		return [(int)$row['capacity'], (int)$row['sold'] + (int)($row['held'] ?? 0)];
	}

	/**
	 * NH-01 — OSS VAT map {CC: bp} replaces the offering rate for B2C
	 * orders whose billing country differs from the seller's. Reverse
	 * charge and disabled OSS both return the offering rate untouched.
	 */
	/** @param array{vat_bp:int} $offering */
	private function vatBpFor(array $offering, bool $reverseCharge, ?string $billingCountry): int
	{
		if ($reverseCharge || !$this->settings->getBool('oss_enabled')) {
			return (int)$offering['vat_bp'];
		}
		$cc = strtoupper(trim((string)$billingCountry));
		$home = strtoupper($this->settings->get('seller_country') ?: 'DE');
		if ($cc === '' || $cc === $home) {
			return (int)$offering['vat_bp'];
		}
		$map = json_decode($this->settings->get('oss_vat_map') ?: '', true);
		if (is_array($map) && isset($map[$cc]) && is_numeric($map[$cc])) {
			$bp = (int)$map[$cc];
			if ($bp >= 0 && $bp <= 10000) {
				return $bp;
			}
		}
		return (int)$offering['vat_bp'];
	}

	/**
	 * Preview helper for the public validate endpoint — must apply the SAME
	 * usability rules as price() (active, window, max uses, offering scope),
	 * otherwise the live estimate promises a discount the charge omits.
	 */
	public function usablePromo(string $code, ?int $offeringId): ?Promo
	{
		$code = trim($code);
		if ($code === '') {
			return null;
		}
		$promo = $this->promos->findByCode($code);
		if ($promo === null || !$this->promoUsable($promo, $offeringId ?? 0)) {
			return null;
		}
		return $promo;
	}

	private function promoUsable(Promo $promo, int $offeringId): bool
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

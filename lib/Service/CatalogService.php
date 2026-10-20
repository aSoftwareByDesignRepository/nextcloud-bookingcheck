<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\Db\AddonMapper;
use OCA\BookingCheck\Db\EventMapper;
use OCA\BookingCheck\Db\Offering;
use OCA\BookingCheck\Db\PriceCatMapper;
use OCA\BookingCheck\Db\SeriesMapper;
use OCA\BookingCheck\Db\SlotMapper;

/**
 * Public catalog read model — what a buyer may pick for one offering.
 * Occurrences, price categories and add-ons are always scoped to the
 * offering (never leak a sibling offering's configuration).
 */
class CatalogService
{
	public function __construct(
		private readonly EventMapper $events,
		private readonly SeriesMapper $series,
		private readonly SlotMapper $slots,
		private readonly PriceCatMapper $priceCats,
		private readonly AddonMapper $addons,
		private readonly ReservationService $inventory,
		private readonly \OCA\BookingCheck\Db\SlotResourceMapper $slotRes,
		private readonly \OCA\BookingCheck\Db\ResourceMapper $resources,
	) {
	}

	public function nextOccurrence(Offering $o): ?string
	{
		if ($o->getType() === 'timeslot') {
			$next = $this->slots->findUpcoming((int)$o->getId(), 1);
			return $next === [] ? null : $next[0]->getStartsAt()?->format(\DateTimeInterface::ATOM);
		}
		$next = $this->events->findUpcoming((int)$o->getId(), 1);
		return $next === [] ? null : $next[0]->getStartsAt()?->format(\DateTimeInterface::ATOM);
	}

	/**
	 * Bookable targets for the buyer picker — each row already carries the
	 * public-contract keys (`target_type`, `id`, `available`).
	 *
	 * @return list<array<string,mixed>>
	 */
	public function occurrenceList(Offering $o): array
	{
		$out = [];
		// notify-mode waitlist is free tier; allocate degrades to notify
		// on unlicensed instances — the join UI shows whenever a mode is set
		$waitlistable = in_array((string)$o->getWaitlistMode(), ['notify', 'allocate'], true);
		if ($o->getType() === 'timeslot') {
			$slotRows = $this->slots->findUpcoming((int)$o->getId());
			// resource pools per slot — the buyer picks one (room, trainer)
			$resNames = [];
			foreach ($this->resources->findByOffering((int)$o->getId()) as $r) {
				$resNames[(int)$r->getId()] = (string)$r->getName();
			}
			$pools = [];
			foreach ($this->slotRes->forSlots(array_map(
				fn ($s) => (int)$s->getId(), $slotRows)) as $sr) {
				$pools[(int)$sr->getSlotId()][] = [
					'id' => (int)$sr->getResourceId(),
					'name' => $resNames[(int)$sr->getResourceId()] ?? '',
					'available' => max(0, (int)$sr->getCapacity() - (int)$sr->getSold() - (int)$sr->getHeld()),
				];
			}
			foreach ($slotRows as $s) {
				$slotPools = $pools[(int)$s->getId()] ?? [];
				// resource-bound slots draw on the pools, not the slot row —
				// its own counters never move, so it would always read "full"
				$free = $slotPools !== []
					? array_sum(array_column($slotPools, 'available'))
					: $this->inventory->available('slot', (int)$s->getId());
				$out[] = [
					'target_type' => 'slot', 'id' => (int)$s->getId(),
					'label' => $s->getStartsAt()?->format('D, j M Y · H:i') ?? '',
					'starts_at' => $s->getStartsAt()?->format(\DateTimeInterface::ATOM),
					'ends_at' => $s->getEndsAt()?->format(\DateTimeInterface::ATOM),
					'available' => $free, 'free' => $free,
					// waitlist on a pooled slot can't pick a pool — keep it off
					'waitlist' => $free <= 0 && $waitlistable && $slotPools === [],
					'resources' => $slotPools,
				];
			}
			return $out;
		}
		foreach ($this->events->findUpcoming((int)$o->getId()) as $e) {
			$free = $this->inventory->available('event', (int)$e->getId());
			$out[] = [
				'target_type' => 'event', 'id' => (int)$e->getId(),
				'label' => $e->getStartsAt()?->format('D, j M Y · H:i') ?? '',
				'starts_at' => $e->getStartsAt()?->format(\DateTimeInterface::ATOM),
				'ends_at' => $e->getEndsAt()?->format(\DateTimeInterface::ATOM),
				'available' => $free, 'free' => $free,
				'waitlist' => $free <= 0 && $waitlistable,
				'series_id' => $e->getSeriesId() !== null ? (int)$e->getSeriesId() : null,
			];
		}
		if ((int)$o->getBookSeries() === 1) {
			foreach ($this->series->findAll() as $ser) {
				if ((int)$ser->getOfferingId() !== (int)$o->getId()) {
					continue;
				}
				$free = $this->inventory->available('series', (int)$ser->getId());
				$out[] = [
					'target_type' => 'series', 'id' => (int)$ser->getId(),
					'label' => (string)$ser->getTitle(),
					'title' => $ser->getTitle(),
					'available' => $free, 'free' => $free,
					'waitlist' => $free <= 0 && $waitlistable,
				];
			}
		}
		return $out;
	}

	/** @return list<array<string,mixed>> active price categories, offering-scoped */
	public function priceCatsFor(Offering $o): array
	{
		return array_map(fn ($c) => $c->jsonSerialize(),
			$this->priceCats->findByOffering((int)$o->getId()));
	}

	/** @return list<array<string,mixed>> active add-ons, offering-scoped */
	public function addonsFor(Offering $o): array
	{
		return array_map(fn ($a) => $a->jsonSerialize(),
			$this->addons->findByOffering((int)$o->getId()));
	}
}

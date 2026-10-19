<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\Exception\CapacityException;
use OCA\BookingCheck\Exception\StateConflictException;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;

/**
 * Atomic seat inventory.
 *
 * Every mutation is a conditional UPDATE — affected-rows=1 means the
 * transition happened, 0 means it was impossible or already stolen. No
 * read-then-write anywhere: the WHERE clause IS the concurrency guard.
 *
 * Lock discipline for multi-target orders: the caller (OrderService) MUST
 * invoke targets in deterministic order (target_type ASC, target_id ASC).
 * Within a series hold, event rows are locked in id-ASC order. This makes
 * inter-transaction deadlock impossible by construction.
 *
 * All methods run inside the caller's transaction — never commit here.
 */
class ReservationService
{
	public const TARGET_EVENT = 'event';
	public const TARGET_SERIES = 'series';
	public const TARGET_SLOT = 'slot';

	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	// ---------------------------------------------------------------
	// Hold  (free → held)
	// ---------------------------------------------------------------

	public function hold(string $targetType, int $targetId, int $seats, ?int $resourceId = null): void
	{
		if ($seats <= 0) {
			return;
		}
		match ($targetType) {
			self::TARGET_EVENT  => $this->holdEvent($targetId, $seats),
			self::TARGET_SERIES => $this->holdSeries($targetId, $seats),
			self::TARGET_SLOT   => $this->holdSlot($targetId, $seats, $resourceId),
			default => throw new StateConflictException('Unknown target type', 'VALIDATION'),
		};
	}

	public function confirm(string $targetType, int $targetId, int $seats, ?int $resourceId = null): void
	{
		if ($seats <= 0) {
			return;
		}
		match ($targetType) {
			self::TARGET_EVENT  => $this->moveEvent($targetId, -$seats, +$seats),
			self::TARGET_SERIES => $this->moveSeries($targetId, -$seats, +$seats),
			self::TARGET_SLOT   => $this->moveSlot($targetId, -$seats, +$seats, $resourceId),
			default => throw new StateConflictException('Unknown target type', 'VALIDATION'),
		};
	}

	public function releaseHold(string $targetType, int $targetId, int $seats, ?int $resourceId = null): void
	{
		if ($seats <= 0) {
			return;
		}
		match ($targetType) {
			self::TARGET_EVENT  => $this->moveEvent($targetId, -$seats, 0),
			self::TARGET_SERIES => $this->moveSeries($targetId, -$seats, 0),
			self::TARGET_SLOT   => $this->moveSlot($targetId, -$seats, 0, $resourceId),
			default => throw new StateConflictException('Unknown target type', 'VALIDATION'),
		};
	}

	/**
	 * Free one or more sold seats. When $offer is true, each freed seat is
	 * transferred atomically into a waitlist offer hold (sold-1, held+1)
	 * so it is never publicly free between release and offer.
	 * Returns the number of seats that became genuinely free (not offered).
	 */
	public function releaseSold(string $targetType, int $targetId, int $seats, ?int $resourceId, callable $offerSeat): int
	{
		$free = 0;
		for ($i = 0; $i < $seats; $i++) {
			// sold-1 only when the seat is actually sold.
			$this->moveSold($targetType, $targetId, -1, $resourceId);
			// Try to transfer the freed seat into an offer hold.
			if ($offerSeat($targetType, $targetId)) {
				$this->hold($targetType, $targetId, 1, $resourceId);
			} else {
				$free++;
			}
		}
		return $free;
	}

	// ---------------------------------------------------------------
	// Availability reads (lock-free; for display only)
	// ---------------------------------------------------------------

	public function available(string $targetType, int $targetId): int
	{
		$qb = $this->db->getQueryBuilder();
		$table = match ($targetType) {
			self::TARGET_EVENT => 'bc_event',
			self::TARGET_SERIES => 'bc_series',
			self::TARGET_SLOT => 'bc_slot',
			default => throw new StateConflictException('Unknown target type', 'VALIDATION'),
		};
		$qb->select('capacity', 'sold', 'held')->from($table)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($targetId)));
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			return 0;
		}
		return max(0, (int)$row['capacity'] - (int)$row['sold'] - (int)$row['held']);
	}

	// ---------------------------------------------------------------
	// Internals — one conditional UPDATE per counter move
	// ---------------------------------------------------------------

	private function holdEvent(int $eventId, int $seats): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_event')
			->set('held', $qb->createFunction('held + ' . $seats))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($eventId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('scheduled')))
			->andWhere($qb->expr()->lte(
				$qb->createFunction('sold + held + ' . $seats),
				$qb->createFunction('capacity'),
			));
		if ($qb->executeStatement() !== 1) {
			throw new CapacityException();
		}
	}

	private function holdSeries(int $seriesId, int $seats): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_series')
			->set('held', $qb->createFunction('held + ' . $seats))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($seriesId)))
			->andWhere($qb->expr()->lte(
				$qb->createFunction('sold + held + ' . $seats),
				$qb->createFunction('capacity'),
			));
		if ($qb->executeStatement() !== 1) {
			throw new CapacityException();
		}
		// Per-occurrence capacity also binds (a full occurrence rejects the
		// series seat even when the series counter has room).
		$events = $this->seriesEventIds($seriesId);
		foreach ($events as $eventId) {
			$this->holdEvent($eventId, $seats);
		}
	}

	private function holdSlot(int $slotId, int $seats, ?int $resourceId): void
	{
		if ($resourceId !== null) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('bc_slot_res')
				->set('held', $qb->createFunction('held + ' . $seats))
				->where($qb->expr()->eq('slot_id', $qb->createNamedParameter($slotId)))
				->andWhere($qb->expr()->eq('resource_id', $qb->createNamedParameter($resourceId)))
				->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(1)))
				->andWhere($qb->expr()->lte(
					$qb->createFunction('sold + held + ' . $seats),
					$qb->createFunction('capacity'),
				));
			if ($qb->executeStatement() !== 1) {
				throw new CapacityException();
			}
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_slot')
			->set('held', $qb->createFunction('held + ' . $seats))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($slotId)))
			->andWhere($qb->expr()->lte(
				$qb->createFunction('sold + held + ' . $seats),
				$qb->createFunction('capacity'),
			));
		if ($qb->executeStatement() !== 1) {
			throw new CapacityException();
		}
	}

	/**
	 * held/sold counter move with underflow guard: `held` may only drop by
	 * $heldDelta (negative) when held >= seats, `sold` may only drop when
	 * sold >= seats. Throws StateConflictException on inconsistency —
	 * a silent floor at 0 would hide double-release bugs.
	 */
	private function moveEvent(int $eventId, int $heldDelta, int $soldDelta): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_event')
			->set('held', $qb->createFunction('held + (' . $heldDelta . ')'))
			->set('sold', $qb->createFunction('sold + (' . $soldDelta . ')'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($eventId)))
			->andWhere($qb->expr()->gte('held', $qb->createNamedParameter(max(0, -$heldDelta))))
			->andWhere($qb->expr()->gte('sold', $qb->createNamedParameter(max(0, -$soldDelta))));
		if ($qb->executeStatement() !== 1) {
			throw new StateConflictException('Inventory counter underflow on event ' . $eventId);
		}
	}

	private function moveSeries(int $seriesId, int $heldDelta, int $soldDelta): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_series')
			->set('held', $qb->createFunction('held + (' . $heldDelta . ')'))
			->set('sold', $qb->createFunction('sold + (' . $soldDelta . ')'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($seriesId)))
			->andWhere($qb->expr()->gte('held', $qb->createNamedParameter(max(0, -$heldDelta))))
			->andWhere($qb->expr()->gte('sold', $qb->createNamedParameter(max(0, -$soldDelta))));
		if ($qb->executeStatement() !== 1) {
			throw new StateConflictException('Inventory counter underflow on series ' . $seriesId);
		}
		foreach ($this->seriesEventIds($seriesId) as $eventId) {
			$this->moveEvent($eventId, $heldDelta, $soldDelta);
		}
	}

	private function moveSlot(int $slotId, int $heldDelta, int $soldDelta, ?int $resourceId): void
	{
		if ($resourceId !== null) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('bc_slot_res')
				->set('held', $qb->createFunction('held + (' . $heldDelta . ')'))
				->set('sold', $qb->createFunction('sold + (' . $soldDelta . ')'))
				->where($qb->expr()->eq('slot_id', $qb->createNamedParameter($slotId)))
				->andWhere($qb->expr()->eq('resource_id', $qb->createNamedParameter($resourceId)))
				->andWhere($qb->expr()->gte('held', $qb->createNamedParameter(max(0, -$heldDelta))))
				->andWhere($qb->expr()->gte('sold', $qb->createNamedParameter(max(0, -$soldDelta))));
			if ($qb->executeStatement() !== 1) {
				throw new StateConflictException('Inventory counter underflow on slot_resource ' . $slotId . '/' . $resourceId);
			}
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_slot')
			->set('held', $qb->createFunction('held + (' . $heldDelta . ')'))
			->set('sold', $qb->createFunction('sold + (' . $soldDelta . ')'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($slotId)))
			->andWhere($qb->expr()->gte('held', $qb->createNamedParameter(max(0, -$heldDelta))))
			->andWhere($qb->expr()->gte('sold', $qb->createNamedParameter(max(0, -$soldDelta))));
		if ($qb->executeStatement() !== 1) {
			throw new StateConflictException('Inventory counter underflow on slot ' . $slotId);
		}
	}

	private function moveSold(string $targetType, int $targetId, int $soldDelta, ?int $resourceId): void
	{
		$this->move($targetType, $targetId, 0, $soldDelta, $resourceId);
	}

	private function move(string $targetType, int $targetId, int $heldDelta, int $soldDelta, ?int $resourceId): void
	{
		match ($targetType) {
			self::TARGET_EVENT  => $this->moveEvent($targetId, $heldDelta, $soldDelta),
			self::TARGET_SERIES => $this->moveSeries($targetId, $heldDelta, $soldDelta),
			self::TARGET_SLOT   => $this->moveSlot($targetId, $heldDelta, $soldDelta, $resourceId),
			default => throw new StateConflictException('Unknown target type', 'VALIDATION'),
		};
	}

	/** @return list<int> event ids of a series, sorted ASC (lock order). */
	private function seriesEventIds(int $seriesId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('bc_event')
			->where($qb->expr()->eq('series_id', $qb->createNamedParameter($seriesId)))
			->orderBy('id', 'ASC');
		return array_map('intval', $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
	}
}

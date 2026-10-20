<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<SlotResource> */
class SlotResourceMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'bc_slot_res', SlotResource::class);
	}

	public function find(int $id): ?SlotResource
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** Row lock for CAS updates — call inside an open transaction. */
	public function findForUpdate(int $id): ?SlotResource
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->forUpdate();
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<SlotResource> */
	public function findAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Any live booking/hold on this slot's pools? Resource-bound slots
	 * keep zero counters on bc_slot — the truth lives here.
	 */
	public function hasBookings(int $slotId): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($this->getTableName())
			->where($qb->expr()->eq('slot_id', $qb->createNamedParameter($slotId)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->gt('sold', $qb->createNamedParameter(0)),
				$qb->expr()->gt('held', $qb->createNamedParameter(0))))
			->setMaxResults(1);
		return $qb->executeQuery()->fetch() !== false;
	}

	/** @return list<SlotResource> all pool rows of a slot, FOR UPDATE —
	 *  serializes pooled holds/releases (which lock the res row, not the
	 *  slot row) against admin-side slot teardown. Call inside a tx. */
	public function lockRowsForSlot(int $slotId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('slot_id', $qb->createNamedParameter($slotId)))
			->orderBy('id', 'ASC')
			->forUpdate();
		return $this->findEntities($qb);
	}

	/** True when the slot is backed by at least one resource-pool row. */
	public function hasRows(int $slotId): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($this->getTableName())
			->where($qb->expr()->eq('slot_id', $qb->createNamedParameter($slotId)))
			->setMaxResults(1);
		return $qb->executeQuery()->fetch() !== false;
	}

	/** @return list<SlotResource> active pools for the given slot ids */
	/** @param list<int> $slotIds */
	public function forSlots(array $slotIds): array
	{
		if ($slotIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->in('slot_id', $qb->createNamedParameter(
				array_map('intval', $slotIds), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(1)))
			->orderBy('slot_id', 'ASC')->addOrderBy('resource_id', 'ASC');
		return $this->findEntities($qb);
	}
}

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

/** @template-extends QBMapper<Resource> */
class ResourceMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'bc_resource', Resource::class);
	}

	public function find(int $id): ?Resource
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
	public function findForUpdate(int $id): ?Resource
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

	/** @return list<Resource> */
	public function findAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<Resource> active resources of one offering */
	public function findByOffering(int $offeringId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('offering_id', $qb->createNamedParameter($offeringId)))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(1)))
			->orderBy('sort', 'ASC')->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Live references to this resource — slot pools or order lines.
	 * A used resource must be deactivated, never deleted (counters and
	 * ticket/roster joins would dangle).
	 */
	public function hasUsage(int $id): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))->from('bc_slot_res')
			->where($qb->expr()->eq('resource_id', $qb->createNamedParameter($id)));
		if ((int)$qb->executeQuery()->fetchOne() > 0) {
			return true;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))->from('bc_order_line')
			->where($qb->expr()->eq('resource_id', $qb->createNamedParameter($id)));
		return (int)$qb->executeQuery()->fetchOne() > 0;
	}
}

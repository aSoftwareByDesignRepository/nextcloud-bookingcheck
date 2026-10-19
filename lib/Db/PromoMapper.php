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

/** @template-extends QBMapper<Promo> */
class PromoMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'bc_promo', Promo::class);
	}

	public function find(int $id): ?Promo
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
	public function findForUpdate(int $id): ?Promo
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

	/** @return list<Promo> */
	public function findAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function findByCode(string $code): ?Promo
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('code', $qb->createNamedParameter($code)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** Atomic use-count bump, guarded by max_uses/active/window. */
	public function incrementUses(int $id): bool
	{
		$now = new \DateTime();
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('uses', $qb->createFunction('uses + 1'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(1)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('max_uses'),
				$qb->expr()->lt('uses', $qb->createFunction('max_uses')),
			))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('valid_from'),
				$qb->expr()->lte('valid_from', $qb->createNamedParameter($now, 'datetime')),
			))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('valid_until'),
				$qb->expr()->gte('valid_until', $qb->createNamedParameter($now, 'datetime')),
			));
		return $qb->executeStatement() === 1;
	}

	public function decrementUses(int $id): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('uses', $qb->createFunction('CASE WHEN uses > 0 THEN uses - 1 ELSE 0 END'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		$qb->executeStatement();
	}
}

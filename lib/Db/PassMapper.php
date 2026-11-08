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

/** @template-extends QBMapper<Pass> */
class PassMapper extends QBMapper
{
	// unique-conflict:ok — claimUse() is a CAS UPDATE on (id,status,uses<max); it never writes token_hash, so bc_pas_tok_uq cannot fire.
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'bc_pass', Pass::class);
	}

	public function find(int $id): ?Pass
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

	public function findByTokenHash(string $hash): ?Pass
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('token_hash', $qb->createNamedParameter($hash)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** Row lock inside an open transaction for the uses CAS update. */
	public function findForUpdate(int $id): ?Pass
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

	/**
	 * CAS admit — claims one use atomically. Returns false when the pass
	 * is not active or exhausted; the unique (pass,target) index on
	 * bc_pass_use additionally forbids re-admission to the same target.
	 */
	public function claimUse(int $id): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('uses', $qb->createFunction('uses + 1'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('active')))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('uses_max'),
				$qb->expr()->lt('uses', 'uses_max')
			));
		return $qb->executeStatement() === 1;
	}

	/** @return list<Pass> */
	public function listForOrder(int $orderId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('order_id', $qb->createNamedParameter($orderId)));
		return $this->findEntities($qb);
	}
}

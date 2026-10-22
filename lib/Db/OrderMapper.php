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

/** @template-extends QBMapper<Order> */
class OrderMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'bc_order', Order::class);
	}

	public function find(int $id): ?Order
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
	public function findForUpdate(int $id): ?Order
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

	/** @return list<Order> */
	public function findAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function findByIdemToken(string $idemToken): ?Order
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('idem_token', $qb->createNamedParameter($idemToken)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function findByRef(string $ref): ?Order
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('ref', $qb->createNamedParameter($ref)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function findByManageTokenHash(string $hash): ?Order
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('manage_token_hash', $qb->createNamedParameter($hash)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** Expirable orders due now — caller locks each row in its own txn. */
	public function findDueExpirables(int $limit = 200): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->in('status', $qb->createNamedParameter(['pending_payment', 'approved', 'pending_approval'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->isNotNull('expires_at'))
			->andWhere($qb->expr()->lt('expires_at', $qb->createNamedParameter(new \DateTime(), 'datetime')))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/** @return list<Order> */
	public function findByOffering(int $offeringId, int $limit = 500): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('offering_id', $qb->createNamedParameter($offeringId)))
			->orderBy('id', 'DESC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/**
	 * Recent orders for the companion manager surface — scope-filtered.
	 * null = unscoped device sees all; an empty array = malformed/empty
	 * scope sees nothing (fail closed, never widen).
	 *
	 * @param list<int>|null $offeringIds
	 * @return list<Order>
	 */
	public function findRecentScoped(?array $offeringIds, int $limit = 50): array
	{
		if ($offeringIds !== null && $offeringIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());
		if ($offeringIds !== null) {
			$qb->where($qb->expr()->in('offering_id', $qb->createNamedParameter(
				$offeringIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
		}
		$qb->orderBy('id', 'DESC')->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	public function findLatestLiveForEmail(string $email): ?Order
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('buyer_email', $qb->createNamedParameter($email)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(
				['paid', 'pending_payment', 'approved', 'pending_approval'],
				\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('id', 'DESC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function rotateManageToken(int $id, string $newHash): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('manage_token_hash', $qb->createNamedParameter($newHash))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		$qb->executeStatement();
	}
}

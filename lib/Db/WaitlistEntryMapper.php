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

/** @template-extends QBMapper<WaitlistEntry> */
class WaitlistEntryMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'bc_waitlist', WaitlistEntry::class);
	}

	public function find(int $id): ?WaitlistEntry
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
	public function findForUpdate(int $id): ?WaitlistEntry
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

	/** @return list<WaitlistEntry> */
	public function findAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** First waiting row, locked — call inside a transaction. */
	public function firstWaitingForUpdate(string $targetType, int $targetId): ?WaitlistEntry
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('target_type', $qb->createNamedParameter($targetType)))
			->andWhere($qb->expr()->eq('target_id', $qb->createNamedParameter($targetId)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter('waiting')))
			->orderBy('id', 'ASC')
			->setMaxResults(1)
			->forUpdate();
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<WaitlistEntry> offers past their expiry */
	public function expiredOffers(int $limit = 200): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter('offered')))
			->andWhere($qb->expr()->isNotNull('offer_expires_at'))
			->andWhere($qb->expr()->lt('offer_expires_at', $qb->createNamedParameter(new \DateTime(), 'datetime')))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	public function findByClaimTokenHash(string $hash): ?WaitlistEntry
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('claim_token_hash', $qb->createNamedParameter($hash)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

}

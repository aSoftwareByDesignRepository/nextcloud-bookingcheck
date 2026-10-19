<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<SyncTask>
 */
class SyncTaskMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'bc_sync_task', SyncTask::class);
	}

	public function insertTask(string $kind, array $payload): SyncTask
	{
		$t = new SyncTask();
		$t->setKind($kind);
		$t->setPayloadJson(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		$t->setStatus('pending');
		$t->setAttempts(0);
		$t->setNextAt(new \DateTimeImmutable());
		$t->setCreatedAt(new \DateTimeImmutable());
		return $this->insert($t);
	}

	/** Due 'pending' rows plus 'claimed' rows whose lease lapsed. */
	public function dueBatch(int $limit = 50): array
	{
		$qb = $this->db->getQueryBuilder();
		$now = new \DateTimeImmutable();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->in('status', $qb->createNamedParameter(
				['pending', 'claimed'], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->lte('next_at',
				$qb->createNamedParameter($now, 'datetime_immutable')))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/**
	 * Atomic lease claim — true iff this worker won the row. Bumps attempts
	 * so it doubles as a fencing epoch; next_at becomes the lease expiry
	 * until a mark call rewrites it.
	 */
	public function claim(int $id, \DateTimeImmutable $lease): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter('claimed'))
			->set('next_at', $qb->createNamedParameter($lease, 'datetime_immutable'))
			->set('attempts', $qb->createFunction('attempts + 1'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(
				['pending', 'claimed'], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->lte('next_at',
				$qb->createNamedParameter(new \DateTimeImmutable(), 'datetime_immutable')));
		return $qb->executeStatement() === 1;
	}

	/** Final CAS — only the epoch owner may flip the row to done. */
	public function markDone(int $id, int $epoch): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter('done'))
			->set('done_at', $qb->createNamedParameter(new \DateTimeImmutable(), 'datetime_immutable'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('claimed')))
			->andWhere($qb->expr()->eq('attempts', $qb->createNamedParameter($epoch)));
		$qb->executeStatement();
	}

	/** Exponential backoff, capped at 24h; dead after $maxAttempts. */
	public function markRetryOrDead(int $id, int $epoch, int $maxAttempts = 8): void
	{
		$dead = $epoch >= $maxAttempts;
		$delayMin = min(24 * 60, (int)(5 * (2 ** min($epoch, 9))));
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter($dead ? 'dead' : 'pending'))
			->set('next_at', $qb->createNamedParameter(new \DateTimeImmutable('+' . $delayMin . ' minutes'), 'datetime_immutable'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('claimed')))
			->andWhere($qb->expr()->eq('attempts', $qb->createNamedParameter($epoch)));
		$qb->executeStatement();
	}
}

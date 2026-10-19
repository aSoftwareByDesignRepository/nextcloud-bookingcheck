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

/** @template-extends QBMapper<EmailOutbox> */
class EmailOutboxMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'bc_email', EmailOutbox::class);
	}

	public function find(int $id): ?EmailOutbox
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
	public function findForUpdate(int $id): ?EmailOutbox
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

	/** @return list<EmailOutbox> */
	public function findAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Queue rows a worker may claim: due 'pending' rows plus 'sending' rows
	 * whose lease expired (the previous worker died mid-send).
	 * @return list<EmailOutbox>
	 */
	public function dueBatch(int $limit = 50): array
	{
		$qb = $this->db->getQueryBuilder();
		$now = new \DateTimeImmutable();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->orX(
				$qb->expr()->andX(
					$qb->expr()->eq('status', $qb->createNamedParameter('pending')),
					$qb->expr()->lt('attempts', $qb->createNamedParameter(4)),
					$qb->expr()->orX(
						$qb->expr()->isNull('next_at'),
						$qb->expr()->lte('next_at', $qb->createNamedParameter($now, 'datetime_immutable'))
					)
				),
				$qb->expr()->andX(
					$qb->expr()->eq('status', $qb->createNamedParameter('sending')),
					$qb->expr()->lte('next_at', $qb->createNamedParameter($now, 'datetime_immutable'))
				)
			))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/**
	 * Atomic lease claim — true iff this worker won the row. Bumps attempts
	 * so the value doubles as a fencing epoch for the final CAS marks, and
	 * a crash-looping mail burns through its 4-claim budget instead of
	 * spinning a poison payload forever.
	 */
	public function claim(int $id, \DateTimeImmutable $lease): bool
	{
		$qb = $this->db->getQueryBuilder();
		$now = new \DateTimeImmutable();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter('sending'))
			->set('next_at', $qb->createNamedParameter($lease, 'datetime_immutable'))
			->set('attempts', $qb->createFunction('attempts + 1'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->andX(
					$qb->expr()->eq('status', $qb->createNamedParameter('pending')),
					$qb->expr()->lt('attempts', $qb->createNamedParameter(4)),
					$qb->expr()->orX(
						$qb->expr()->isNull('next_at'),
						$qb->expr()->lte('next_at', $qb->createNamedParameter($now, 'datetime_immutable'))
					)
				),
				$qb->expr()->andX(
					$qb->expr()->eq('status', $qb->createNamedParameter('sending')),
					$qb->expr()->lte('next_at', $qb->createNamedParameter($now, 'datetime_immutable'))
				)
			));
		return $qb->executeStatement() === 1;
	}

	/** Final CAS — only the epoch owner may flip the row to sent. */
	public function markSent(int $id, int $epoch): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter('sent'))
			->set('sent_at', $qb->createNamedParameter(new \DateTimeImmutable(), 'datetime_immutable'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('sending')))
			->andWhere($qb->expr()->eq('attempts', $qb->createNamedParameter($epoch)));
		$qb->executeStatement();
	}

	/** Failed send — back to pending with retry backoff, or dead at the cap. */
	public function markFailed(int $id, int $epoch, bool $dead): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter($dead ? 'dead' : 'pending'))
			->set('next_at', $qb->createNamedParameter(
				$dead ? null : new \DateTimeImmutable('+' . (2 ** $epoch) . ' minutes'),
				'datetime_immutable'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('sending')))
			->andWhere($qb->expr()->eq('attempts', $qb->createNamedParameter($epoch)));
		$qb->executeStatement();
	}
}

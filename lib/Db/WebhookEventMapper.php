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

/** @template-extends QBMapper<WebhookEvent> */
class WebhookEventMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'bc_webhook_evt', WebhookEvent::class);
	}

	public function find(int $id): ?WebhookEvent
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
	public function findForUpdate(int $id): ?WebhookEvent
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

	/** @return list<WebhookEvent> */
	public function findAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Claim an event id: true = this caller owns processing, false = replay.
	 * A duplicate whose claim went stale (received long ago, never marked
	 * processed — i.e. the worker died mid-apply between claim and
	 * markProcessed) is reclaimed here so a PSP retry re-enters instead of
	 * being acked 'duplicate' forever while the payment stays unapplied.
	 */
	private const STALE_CLAIM_MINUTES = 30;

	public function claim(string $provider, string $eventId): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->insert($this->getTableName())
			->setValue('provider', $qb->createNamedParameter($provider))
			->setValue('event_id', $qb->createNamedParameter($eventId))
			->setValue('received_at', $qb->createNamedParameter(new \DateTime(), 'datetime'));
		try {
			$qb->executeStatement();
			return true;
		} catch (\OCP\DB\Exception $e) {
			if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
		}
		// unique hit — try to take over a dead claim. processed_at IS NULL
		// + old received_at = abandoned; a fresh duplicate loses the CAS
		// and correctly reports replay.
		$staleBefore = new \DateTime('-' . self::STALE_CLAIM_MINUTES . ' minutes');
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('received_at', $qb->createNamedParameter(new \DateTime(), 'datetime'))
			->where($qb->expr()->eq('provider', $qb->createNamedParameter($provider)))
			->andWhere($qb->expr()->eq('event_id', $qb->createNamedParameter($eventId)))
			->andWhere($qb->expr()->isNull('processed_at'))
			->andWhere($qb->expr()->lt('received_at',
				$qb->createNamedParameter($staleBefore, 'datetime')));
		return $qb->executeStatement() === 1;
	}

	public function markProcessed(string $provider, string $eventId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('processed_at', $qb->createNamedParameter(new \DateTime(), 'datetime'))
			->where($qb->expr()->eq('provider', $qb->createNamedParameter($provider)))
			->andWhere($qb->expr()->eq('event_id', $qb->createNamedParameter($eventId)));
		$qb->executeStatement();
	}

	/**
	 * Release a claim that was never processed (apply() threw). Guarded by
	 * processed_at IS NULL so a successfully processed event can never be
	 * re-claimed — retries of a failed event re-enter the pipeline instead
	 * of being swallowed as duplicates.
	 */
	public function release(string $provider, string $eventId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('provider', $qb->createNamedParameter($provider)))
			->andWhere($qb->expr()->eq('event_id', $qb->createNamedParameter($eventId)))
			->andWhere($qb->expr()->isNull('processed_at'));
		$qb->executeStatement();
	}

}

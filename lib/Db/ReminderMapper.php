<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/** @template-extends QBMapper<Reminder> */
class ReminderMapper extends QBMapper
{
	public function __construct(
		IDBConnection $db,
		private readonly ?LoggerInterface $logger = null,
	) {
		parent::__construct($db, 'bc_reminder', Reminder::class);
	}

	/** Insert-if-absent dedupe — returns true when THIS caller claimed the send. */
	public function claim(int $orderId, string $targetType, int $targetId): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->insert($this->getTableName())
			->setValue('order_id', $qb->createNamedParameter($orderId))
			->setValue('target_type', $qb->createNamedParameter($targetType))
			->setValue('target_id', $qb->createNamedParameter($targetId))
			->setValue('sent_at', $qb->createNamedParameter(new \DateTimeImmutable(), 'datetime_immutable'));
		try {
			$qb->executeStatement();
			return true;
		} catch (\OCP\DB\Exception $e) {
			// only the unique index means "already sent" — a transient DB
			// error must abort loudly, not silently drop the reminder
			if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			return false;
		}
	}

	/**
	 * Undo a claim when the send itself failed — the next run retries.
	 * Only called by the claiming run, so no dedupe race: the unique row
	 * either stays (concurrent winner) or is removed for a clean retry.
	 */
	public function release(int $orderId, string $targetType, int $targetId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('order_id', $qb->createNamedParameter($orderId)))
			->andWhere($qb->expr()->eq('target_type', $qb->createNamedParameter($targetType)))
			->andWhere($qb->expr()->eq('target_id', $qb->createNamedParameter($targetId)));
		try {
			$qb->executeStatement();
		} catch (\Throwable $e) {
			// best-effort — a stuck claim just skips the reminder once,
			// but the failure must be visible in the log
			$this->logger?->warning('Reminder claim release failed for order ' . $orderId . ': ' . $e->getMessage());
		}
	}
}

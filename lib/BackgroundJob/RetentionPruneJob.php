<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\BackgroundJob;

use OCA\BookingCheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/** Unbounded-growth pruning (§9.13 retention table). GoBD rows are never pruned. */
class RetentionPruneJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly IDBConnection $db,
		private readonly ILockingProvider $locking,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
	}

	protected function run($argument): void
	{
		// Shared lock on the upgrade-backup key: a restore holds it
		// exclusively (UpgradeBackupService::runExclusive) — app-table
		// scans/writes must not interleave with a mid-restore
		// truncate+reinsert. A skip is not a failure: TimedJobs re-run
		// on their interval.
		try {
			$this->locking->acquireLock(
				UpgradeBackupService::LOCK_KEY,
				ILockingProvider::LOCK_SHARED,
				'BookingCheck retention prune',
			);
		} catch (LockedException) {
			$this->logger->info('BookingCheck: skipping retention prune — upgrade backup/restore in progress');
			return;
		}
		try {
			$this->doRun($argument);
		} finally {
			$this->locking->releaseLock(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED);
		}
	}

	private function doRun($argument): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete('bc_otp')->where($qb->expr()->lt('expires_at',
			$qb->createNamedParameter(new \DateTimeImmutable('-7 days'), 'datetime_immutable')));
		$qb->executeStatement();

		// expires_at is the sliding expiry — idle-dead sessions are prunable
		// even when the absolute cap is still ahead (expires_at <= abs always)
		$qb = $this->db->getQueryBuilder();
		$qb->delete('bc_portal_sess')->where($qb->expr()->lt('expires_at',
			$qb->createNamedParameter(new \DateTimeImmutable('-7 days'), 'datetime_immutable')));
		$qb->executeStatement();

		$d90 = new \DateTimeImmutable('-90 days');
		$qb = $this->db->getQueryBuilder();
		$qb->delete('bc_webhook_dlvr')
			->where($qb->expr()->eq('status', $qb->createNamedParameter('delivered')))
			->andWhere($qb->expr()->lt('delivered_at',
				$qb->createNamedParameter($d90, 'datetime_immutable')));
		$qb->executeStatement();
		$qb = $this->db->getQueryBuilder();
		$qb->delete('bc_webhook_dlvr')
			->where($qb->expr()->eq('status', $qb->createNamedParameter('dead')))
			->andWhere($qb->expr()->lt('created_at',
				$qb->createNamedParameter($d90, 'datetime_immutable')));
		$qb->executeStatement();
		$qb = $this->db->getQueryBuilder();
		$qb->delete('bc_webhook_evt')
			->where($qb->expr()->isNotNull('processed_at'))
			->andWhere($qb->expr()->lt('processed_at',
				$qb->createNamedParameter($d90, 'datetime_immutable')));
		$qb->executeStatement();
		// queue rows are transient — sent/dead mail is not a legal record
		$qb = $this->db->getQueryBuilder();
		$qb->delete('bc_email')
			->where($qb->expr()->eq('status', $qb->createNamedParameter('sent')))
			->andWhere($qb->expr()->lt('sent_at',
				$qb->createNamedParameter($d90, 'datetime_immutable')));
		$qb->executeStatement();
		$qb = $this->db->getQueryBuilder();
		$qb->delete('bc_email')
			->where($qb->expr()->eq('status', $qb->createNamedParameter('dead')))
			->andWhere($qb->expr()->lt('created_at',
				$qb->createNamedParameter($d90, 'datetime_immutable')));
		$qb->executeStatement();
		// finished sync tasks are operational noise, not audit
		$qb = $this->db->getQueryBuilder();
		$qb->delete('bc_sync_task')
			->where($qb->expr()->in('status', $qb->createNamedParameter(['done', 'dead'],
				\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->lt('created_at',
				$qb->createNamedParameter($d90, 'datetime_immutable')));
		$qb->executeStatement();
	}
}

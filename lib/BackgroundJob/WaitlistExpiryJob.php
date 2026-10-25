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

/** Terminal-state waitlist rows pruned after 180d (unbounded-growth guard). */
class WaitlistExpiryJob extends TimedJob
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
				'BookingCheck waitlist expiry',
			);
		} catch (LockedException) {
			$this->logger->info('BookingCheck: skipping waitlist expiry — upgrade backup/restore in progress');
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
		$qb->delete('bc_waitlist')
			->where($qb->expr()->in('state', $qb->createNamedParameter(
				['expired', 'declined', 'claimed'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->lt('created_at',
				$qb->createNamedParameter(new \DateTime('-180 days'), 'datetime')));
		$qb->executeStatement();
	}
}

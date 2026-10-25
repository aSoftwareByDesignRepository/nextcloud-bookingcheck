<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\BackgroundJob;

use OCA\BookingCheck\Service\UpgradeBackupService;
use OCA\BookingCheck\Service\WaitlistService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

class WaitlistAllocationJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly WaitlistService $waitlist,
		private readonly ILockingProvider $locking,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(300);
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
				'BookingCheck waitlist allocation',
			);
		} catch (LockedException) {
			$this->logger->info('BookingCheck: skipping waitlist allocation — upgrade backup/restore in progress');
			return;
		}
		try {
			// Allocation happens inline at seat-free time (OrderService) — this
			// job is the reconciliation sweep for anything missed.
			$this->waitlist->expireOffers();
		} finally {
			$this->locking->releaseLock(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED);
		}
	}
}

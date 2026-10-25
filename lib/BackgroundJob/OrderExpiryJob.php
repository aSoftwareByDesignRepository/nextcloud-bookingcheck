<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\BackgroundJob;

use OCA\BookingCheck\Db\OrderMapper;
use OCA\BookingCheck\Service\OrderService;
use OCA\BookingCheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Expire pending_payment / approved / pending_approval orders whose TTL
 * elapsed. Each row transitions inside its own txn (CAS vs webhook race).
 */
class OrderExpiryJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly OrderMapper $orders,
		private readonly OrderService $orderService,
		private readonly ILockingProvider $locking,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(300); // 5 min
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
				'BookingCheck order expiry',
			);
		} catch (LockedException) {
			$this->logger->info('BookingCheck: skipping order expiry — upgrade backup/restore in progress');
			return;
		}
		try {
			foreach ($this->orders->findDueExpirables() as $order) {
				$this->orderService->expire($order);
			}
		} finally {
			$this->locking->releaseLock(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED);
		}
	}
}

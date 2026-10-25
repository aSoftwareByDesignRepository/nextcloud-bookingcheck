<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\BackgroundJob;

use OCA\BookingCheck\Db\SyncTaskMapper;
use OCA\BookingCheck\Service\CrmSyncService;
use OCA\BookingCheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/** Delivers queued sibling-sync tasks (customercheck buyers) with retry. */
class CrmSyncJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly SyncTaskMapper $tasks,
		private readonly CrmSyncService $crm,
		private readonly LoggerInterface $logger,
		private readonly ILockingProvider $locking,
	) {
		parent::__construct($time);
		$this->setInterval(300); // every 5 min — behind the email cycle
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
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
				'BookingCheck CRM sync',
			);
		} catch (LockedException) {
			$this->logger->info('BookingCheck: skipping CRM sync — upgrade backup/restore in progress');
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
		$lease = new \DateTimeImmutable('+15 minutes');
		foreach ($this->tasks->dueBatch() as $task) {
			$id = (int)$task->getId();
			$epoch = (int)$task->getAttempts() + 1; // post-claim claim count
			if (!$this->tasks->claim($id, $lease)) {
				continue; // another worker took it
			}
			$payload = json_decode((string)$task->getPayloadJson(), true);
			if (!is_array($payload)) {
				$this->tasks->markDone($id, $epoch); // unfixable — don't retry garbage
				continue;
			}
			$ok = match ($task->getKind()) {
				'crm_buyer' => $this->crm->deliver($payload),
				default => true, // unknown kind: drop rather than spin forever
			};
			if ($ok) {
				$this->tasks->markDone($id, $epoch);
			} else {
				$this->tasks->markRetryOrDead($id, $epoch);
				if ($epoch >= 8) {
					$this->logger->error('bookingcheck: sync task dead after retries', [
						'kind' => $task->getKind(), 'id' => $id,
					]);
				}
			}
		}
	}
}

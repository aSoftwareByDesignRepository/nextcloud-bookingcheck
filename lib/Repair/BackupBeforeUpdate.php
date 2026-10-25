<?php

declare(strict_types=1);

/**
 * Pre-migration repair step: snapshot BookingCheck data before schema migrations run.
 *
 * Registered under {@see info.xml} `<repair-steps><pre-migration>` so every
 * `occ app:update bookingcheck` and app reinstall over an existing version creates
 * a recoverable backup before migrations mutate the database.
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Repair;

use OCA\BookingCheck\Exception\UpgradeBackupException;
use OCA\BookingCheck\Service\UpgradeBackupService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

final class BackupBeforeUpdate implements IRepairStep
{
	public function __construct(
		private readonly UpgradeBackupService $backupService,
	) {
	}

	public function getName(): string
	{
		return 'Back up BookingCheck data before update migrations';
	}

	public function run(IOutput $output): void
	{
		if (!$this->backupService->hasDataToBackup()) {
			$output->info('BookingCheck: no existing tables to back up (fresh install); skipping pre-update snapshot.');
			return;
		}

		try {
			$result = $this->backupService->createSnapshot('pre-migration');
		} catch (UpgradeBackupException $e) {
			$output->warning('BookingCheck: pre-update backup failed: ' . $e->getMessage());
			throw $e;
		}

		$tableCount = count($result['manifest']['tables'] ?? []);
		$output->info(sprintf(
			'BookingCheck: pre-update backup created (%s, %d table(s)). '
			. 'Restore with `occ bookingcheck:upgrade-backup restore --latest --force` if needed.',
			$result['id'],
			$tableCount,
		));
	}
}

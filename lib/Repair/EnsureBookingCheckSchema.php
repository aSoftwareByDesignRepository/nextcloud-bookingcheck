<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Repair;

use OC\DB\Connection;
use OC\DB\MigrationService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\Server;

/**
 * Safety net when migrations were marked complete without creating every table.
 */
final class EnsureBookingCheckSchema implements IRepairStep
{
	public function __construct(
		private readonly IDBConnection $connection,
		private readonly IConfig $config,
	) {
	}

	public function getName(): string
	{
		return 'Ensure BookingCheck database schema is complete';
	}

	public function run(IOutput $output): void
	{
		$this->config->deleteAppValue(UninstallDropTables::APP_ID, UninstallDropTables::REPAIR_PASS_KEY);

		$missingBefore = $this->missingTables();
		if ($missingBefore === []) {
			$output->info('BookingCheck: all ' . count(UninstallDropTables::TABLES) . ' tables are present.');
			return;
		}

		$output->info(sprintf(
			'BookingCheck: %d table(s) missing (%s); running pending migrations.',
			count($missingBefore),
			implode(', ', $missingBefore),
		));

		$migrationService = new MigrationService(
			UninstallDropTables::APP_ID,
			Server::get(Connection::class),
		);
		$migrationService->migrate('latest', false);

		$missingAfter = $this->missingTables();
		if ($missingAfter !== []) {
			throw new \RuntimeException(sprintf(
				'BookingCheck schema is still incomplete after migrate("latest"). Missing: %s. '
				. 'Run `php occ upgrade` or re-enable the app and check nextcloud.log.',
				implode(', ', $missingAfter),
			));
		}
		$output->info('BookingCheck: schema repair completed; all tables are now present.');
	}

	/** @return list<string> */
	private function missingTables(): array
	{
		$missing = [];
		foreach (UninstallDropTables::TABLES as $table) {
			if (!$this->connection->tableExists($table)) {
				$missing[] = $table;
			}
		}
		return $missing;
	}
}

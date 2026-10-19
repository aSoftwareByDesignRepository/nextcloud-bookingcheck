<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\BackgroundJob;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IDBConnection;

/** Unbounded-growth pruning (§9.13 retention table). GoBD rows are never pruned. */
class RetentionPruneJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly IDBConnection $db,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
	}

	protected function run($argument): void
	{
		$this->prune('bc_otp', 'expires_at', '-7 days');
		$this->prune('bc_portal_sess', 'expires_at', '-7 days');
		$this->pruneWhere('bc_webhook_dlvr', "status = 'delivered' AND delivered_at < " . $this->cutoff('-90 days'));
		$this->pruneWhere('bc_webhook_dlvr', "status = 'dead' AND created_at < " . $this->cutoff('-90 days'));
		$this->pruneWhere('bc_webhook_evt', "processed_at IS NOT NULL AND processed_at < " . $this->cutoff('-90 days'));
	}

	private function prune(string $table, string $col, string $olderThan): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($table)
			->where($qb->expr()->lt($col, $qb->createNamedParameter(new \DateTime($olderThan), 'datetime')));
		$qb->executeStatement();
	}

	private function pruneWhere(string $table, string $where): void
	{
		$this->db->executeStatement('DELETE FROM `*PREFIX*' . $table . '` WHERE ' . $where);
	}

	private function cutoff(string $olderThan): string
	{
		return "'" . (new \DateTime($olderThan))->format('Y-m-d H:i:s') . "'";
	}
}

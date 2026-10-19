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

/** Terminal-state waitlist rows pruned after 180d (unbounded-growth guard). */
class WaitlistExpiryJob extends TimedJob
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
		$qb = $this->db->getQueryBuilder();
		$qb->delete('bc_waitlist')
			->where($qb->expr()->in('state', $qb->createNamedParameter(
				['expired', 'declined', 'claimed'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->lt('created_at',
				$qb->createNamedParameter(new \DateTime('-180 days'), 'datetime')));
		$qb->executeStatement();
	}
}

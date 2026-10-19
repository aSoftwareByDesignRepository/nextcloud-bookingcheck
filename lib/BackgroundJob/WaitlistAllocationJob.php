<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\BackgroundJob;

use OCA\BookingCheck\Service\WaitlistService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

class WaitlistAllocationJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly WaitlistService $waitlist,
	) {
		parent::__construct($time);
		$this->setInterval(300);
	}

	protected function run($argument): void
	{
		// Allocation happens inline at seat-free time (OrderService) — this
		// job is the reconciliation sweep for anything missed.
		$this->waitlist->expireOffers();
	}
}

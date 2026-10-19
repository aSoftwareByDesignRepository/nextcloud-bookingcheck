<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\BackgroundJob;

use OCA\BookingCheck\Service\EolService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

class EolJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly EolService $eol,
	) {
		parent::__construct($time);
		$this->setInterval(86400); // daily
	}

	protected function run($argument): void
	{
		$this->eol->processDue();
	}
}

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\BackgroundJob;

use OCA\BookingCheck\Service\SlotMaterializer;
use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;

/** Keeps the timeslot horizon rolling — materialization is idempotent,
 *  so a daily sweep is enough. */
class SlotMaterializeJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly SlotMaterializer $materializer,
	) {
		parent::__construct($time);
		$this->setInterval(86400); // daily
	}

	protected function run($argument): void
	{
		$this->materializer->materializeAll();
	}
}

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\BackgroundJob;

use OCA\BookingCheck\Db\OrderMapper;
use OCA\BookingCheck\Service\OrderService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

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
	) {
		parent::__construct($time);
		$this->setInterval(300); // 5 min
	}

	protected function run($argument): void
	{
		foreach ($this->orders->findDueExpirables() as $order) {
			$this->orderService->expire($order);
		}
	}
}

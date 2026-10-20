<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/** Dedupe row for NH-20 attendee reminders — one mail per (order,target). */
class Reminder extends Entity
{
	protected $orderId;
	protected $targetType;
	protected $targetId;
	protected $sentAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('orderId', 'integer');
		$this->addType('targetId', 'integer');
		$this->addType('sentAt', 'datetime_immutable');
	}
}

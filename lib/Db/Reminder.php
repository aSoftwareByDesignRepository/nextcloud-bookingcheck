<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the `bc_reminder` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getOrderId()
 * @method void setOrderId(int $orderId)
 * @method string getTargetType()
 * @method void setTargetType(string $targetType)
 * @method int getTargetId()
 * @method void setTargetId(int $targetId)
 * @method \DateTimeImmutable getSentAt()
 * @method void setSentAt(\DateTimeImmutable $sentAt)
 */
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

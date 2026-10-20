<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the `bc_pass_use` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getPassId()
 * @method void setPassId(int $passId)
 * @method string getTargetType()
 * @method void setTargetType(string $targetType)
 * @method int getTargetId()
 * @method void setTargetId(int $targetId)
 * @method string getDevice()
 * @method void setDevice(string $device)
 * @method \DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(\DateTimeImmutable $createdAt)
 */
class PassUse extends Entity
{
	protected $passId;
	protected $targetType;
	protected $targetId;
	protected $device;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('passId', 'integer');
		$this->addType('targetId', 'integer');
		$this->addType('createdAt', 'datetime_immutable');
	}
}

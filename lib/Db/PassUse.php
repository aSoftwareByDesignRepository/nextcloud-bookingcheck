<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

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

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/** Append-only NH-10 download analytics row. No PII beyond order linkage. */
class DownloadLog extends Entity
{
	protected $materialId;
	protected $orderId;
	protected $ticketId;
	protected $actor;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('materialId', 'integer');
		$this->addType('orderId', 'integer');
		$this->addType('ticketId', 'integer');
		$this->addType('createdAt', 'datetime_immutable');
	}
}

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the `bc_download` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getMaterialId()
 * @method void setMaterialId(int $materialId)
 * @method int|null getOrderId()
 * @method void setOrderId(?int $orderId)
 * @method int|null getTicketId()
 * @method void setTicketId(?int $ticketId)
 * @method string getActor()
 * @method void setActor(string $actor)
 * @method \DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(\DateTimeImmutable $createdAt)
 */
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

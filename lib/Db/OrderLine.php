<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the `bc_order_line` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getOrderId()
 * @method void setOrderId(int $orderId)
 * @method string getTargetType()
 * @method void setTargetType(string $targetType)
 * @method int getTargetId()
 * @method void setTargetId(int $targetId)
 * @method string getLineKind()
 * @method void setLineKind(string $lineKind)
 * @method int|null getResourceId()
 * @method void setResourceId(?int $resourceId)
 * @method int|null getPriceCatId()
 * @method void setPriceCatId(?int $priceCatId)
 * @method string|null getCatName()
 * @method void setCatName(?string $catName)
 * @method int getSeats()
 * @method void setSeats(int $seats)
 * @method int getUnitCents()
 * @method void setUnitCents(int $unitCents)
 * @method int getLineCents()
 * @method void setLineCents(int $lineCents)
 */
class OrderLine extends Entity
{
	protected $orderId;
	protected $targetType;
	protected $targetId;
	protected $lineKind;
	protected $resourceId;
	protected $priceCatId;
	protected $catName;
	protected $seats;
	protected $unitCents;
	protected $lineCents;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('orderId', 'integer');
		$this->addType('targetId', 'integer');
		$this->addType('resourceId', 'integer');
		$this->addType('priceCatId', 'integer');
		$this->addType('seats', 'integer');
		$this->addType('unitCents', 'integer');
		$this->addType('lineCents', 'integer');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'orderId' => (int)$this->orderId,
			'targetType' => $this->targetType,
			'targetId' => (int)$this->targetId,
			'lineKind' => $this->lineKind,
			'resourceId' => $this->resourceId === null ? null : (int)$this->resourceId,
			'priceCatId' => $this->priceCatId === null ? null : (int)$this->priceCatId,
			'catName' => $this->catName,
			'seats' => (int)$this->seats,
			'unitCents' => (int)$this->unitCents,
			'lineCents' => (int)$this->lineCents,
		];
	}
}

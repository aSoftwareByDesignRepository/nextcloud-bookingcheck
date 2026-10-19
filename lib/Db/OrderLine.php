<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

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

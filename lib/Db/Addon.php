<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the `bc_addon` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getOfferingId()
 * @method void setOfferingId(int $offeringId)
 * @method string getName()
 * @method void setName(string $name)
 * @method int getPriceCents()
 * @method void setPriceCents(int $priceCents)
 * @method int|null getMaxPerOrder()
 * @method void setMaxPerOrder(?int $maxPerOrder)
 * @method int getActive()
 * @method void setActive(int $active)
 */
class Addon extends Entity
{
	protected $offeringId;
	protected $name;
	protected $priceCents;
	protected $maxPerOrder;
	protected $active;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('offeringId', 'integer');
		$this->addType('priceCents', 'integer');
		$this->addType('maxPerOrder', 'integer');
		$this->addType('active', 'integer');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'offeringId' => (int)$this->offeringId,
			'name' => $this->name,
			'priceCents' => (int)$this->priceCents,
			'maxPerOrder' => $this->maxPerOrder === null ? null : (int)$this->maxPerOrder,
			'active' => (int)$this->active,
		];
	}
}

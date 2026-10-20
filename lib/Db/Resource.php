<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the `bc_resource` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getOfferingId()
 * @method void setOfferingId(int $offeringId)
 * @method string getName()
 * @method void setName(string $name)
 * @method int getCapacity()
 * @method void setCapacity(int $capacity)
 * @method int getSort()
 * @method void setSort(int $sort)
 * @method int getActive()
 * @method void setActive(int $active)
 */
class Resource extends Entity
{
	protected $offeringId;
	protected $name;
	protected $capacity;
	protected $sort;
	protected $active;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('offeringId', 'integer');
		$this->addType('capacity', 'integer');
		$this->addType('sort', 'integer');
		$this->addType('active', 'integer');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'offeringId' => (int)$this->offeringId,
			'name' => $this->name,
			'capacity' => (int)$this->capacity,
			'sort' => (int)$this->sort,
			'active' => (int)$this->active,
		];
	}
}

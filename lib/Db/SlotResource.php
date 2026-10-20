<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the `bc_slot_res` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getSlotId()
 * @method void setSlotId(int $slotId)
 * @method int getResourceId()
 * @method void setResourceId(int $resourceId)
 * @method int getCapacity()
 * @method void setCapacity(int $capacity)
 * @method int getSold()
 * @method void setSold(int $sold)
 * @method int getHeld()
 * @method void setHeld(int $held)
 * @method int getActive()
 * @method void setActive(int $active)
 */
class SlotResource extends Entity
{
	protected $slotId;
	protected $resourceId;
	protected $capacity;
	protected $sold;
	protected $held;
	protected $active;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('slotId', 'integer');
		$this->addType('resourceId', 'integer');
		$this->addType('capacity', 'integer');
		$this->addType('sold', 'integer');
		$this->addType('held', 'integer');
		$this->addType('active', 'integer');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'slotId' => (int)$this->slotId,
			'resourceId' => (int)$this->resourceId,
			'capacity' => (int)$this->capacity,
			'sold' => (int)$this->sold,
			'held' => (int)$this->held,
			'active' => (int)$this->active,
		];
	}
}

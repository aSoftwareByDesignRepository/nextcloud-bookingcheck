<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the `bc_series` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getOfferingId()
 * @method void setOfferingId(int $offeringId)
 * @method string|null getTitle()
 * @method void setTitle(?string $title)
 * @method string|null getRrule()
 * @method void setRrule(?string $rrule)
 * @method int getCapacity()
 * @method void setCapacity(int $capacity)
 * @method int getSold()
 * @method void setSold(int $sold)
 * @method int getHeld()
 * @method void setHeld(int $held)
 */
class Series extends Entity
{
	protected $offeringId;
	protected $title;
	protected $rrule;
	protected $capacity;
	protected $sold;
	protected $held;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('offeringId', 'integer');
		$this->addType('capacity', 'integer');
		$this->addType('sold', 'integer');
		$this->addType('held', 'integer');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'offeringId' => (int)$this->offeringId,
			'title' => $this->title,
			'rrule' => $this->rrule,
			'capacity' => (int)$this->capacity,
			'sold' => (int)$this->sold,
			'held' => (int)$this->held,
		];
	}
}

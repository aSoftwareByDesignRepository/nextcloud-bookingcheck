<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the `bc_slot` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getTplId()
 * @method void setTplId(int $tplId)
 * @method int getOfferingId()
 * @method void setOfferingId(int $offeringId)
 * @method \DateTimeImmutable getStartsAt()
 * @method void setStartsAt(\DateTimeImmutable $startsAt)
 * @method \DateTimeImmutable getEndsAt()
 * @method void setEndsAt(\DateTimeImmutable $endsAt)
 * @method int getCapacity()
 * @method void setCapacity(int $capacity)
 * @method int getSold()
 * @method void setSold(int $sold)
 * @method int getHeld()
 * @method void setHeld(int $held)
 */
class Slot extends Entity
{
	protected $tplId;
	protected $offeringId;
	protected $startsAt;
	protected $endsAt;
	protected $capacity;
	protected $sold;
	protected $held;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('tplId', 'integer');
		$this->addType('offeringId', 'integer');
		$this->addType('startsAt', 'datetime_immutable');
		$this->addType('endsAt', 'datetime_immutable');
		$this->addType('capacity', 'integer');
		$this->addType('sold', 'integer');
		$this->addType('held', 'integer');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'tplId' => (int)$this->tplId,
			'offeringId' => (int)$this->offeringId,
			'startsAt' => $this->startsAt === null ? null : $this->startsAt->format(\DateTimeInterface::ATOM),
			'endsAt' => $this->endsAt === null ? null : $this->endsAt->format(\DateTimeInterface::ATOM),
			'capacity' => (int)$this->capacity,
			'sold' => (int)$this->sold,
			'held' => (int)$this->held,
		];
	}
}

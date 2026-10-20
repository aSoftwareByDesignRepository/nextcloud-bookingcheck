<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the `bc_event` table.
 *
 * @method int getId()
 * @method void setId(int $id)
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
 * @method int|null getPriceCents()
 * @method void setPriceCents(?int $priceCents)
 * @method int getHeld()
 * @method void setHeld(int $held)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method int|null getSeriesId()
 * @method void setSeriesId(?int $seriesId)
 */
class Event extends Entity
{
	protected $offeringId;
	protected $startsAt;
	protected $endsAt;
	protected $capacity;
	protected $sold;
	protected $priceCents;
	protected $held;
	protected $status;
	protected $seriesId;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('offeringId', 'integer');
		$this->addType('startsAt', 'datetime_immutable');
		$this->addType('endsAt', 'datetime_immutable');
		$this->addType('capacity', 'integer');
		$this->addType('sold', 'integer');
		$this->addType('priceCents', 'integer');
		$this->addType('held', 'integer');
		$this->addType('seriesId', 'integer');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'offeringId' => (int)$this->offeringId,
			'startsAt' => $this->startsAt === null ? null : $this->startsAt->format(\DateTimeInterface::ATOM),
			'endsAt' => $this->endsAt === null ? null : $this->endsAt->format(\DateTimeInterface::ATOM),
			'capacity' => (int)$this->capacity,
			'priceCents' => $this->priceCents === null ? null : (int)$this->priceCents,
			'sold' => (int)$this->sold,
			'held' => (int)$this->held,
			'status' => $this->status,
			'seriesId' => $this->seriesId === null ? null : (int)$this->seriesId,
		];
	}
}

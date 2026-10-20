<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the `bc_slot_tpl` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getOfferingId()
 * @method void setOfferingId(int $offeringId)
 * @method int getDurationMin()
 * @method void setDurationMin(int $durationMin)
 * @method int getCapPerSlot()
 * @method void setCapPerSlot(int $capPerSlot)
 * @method int getBufferMin()
 * @method void setBufferMin(int $bufferMin)
 * @method int getLeadMin()
 * @method void setLeadMin(int $leadMin)
 * @method int getHorizonDays()
 * @method void setHorizonDays(int $horizonDays)
 * @method string|null getWeeklyRrule()
 * @method void setWeeklyRrule(?string $weeklyRrule)
 * @method int|null getResourceId()
 * @method void setResourceId(?int $resourceId)
 */
class SlotTpl extends Entity
{
	protected $offeringId;
	protected $durationMin;
	protected $capPerSlot;
	protected $bufferMin;
	protected $leadMin;
	protected $horizonDays;
	protected $weeklyRrule;
	protected $resourceId;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('offeringId', 'integer');
		$this->addType('durationMin', 'integer');
		$this->addType('capPerSlot', 'integer');
		$this->addType('bufferMin', 'integer');
		$this->addType('leadMin', 'integer');
		$this->addType('horizonDays', 'integer');
		$this->addType('resourceId', 'integer');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'offeringId' => (int)$this->offeringId,
			'durationMin' => (int)$this->durationMin,
			'capPerSlot' => (int)$this->capPerSlot,
			'bufferMin' => (int)$this->bufferMin,
			'leadMin' => (int)$this->leadMin,
			'horizonDays' => (int)$this->horizonDays,
			'weeklyRrule' => $this->weeklyRrule,
			'resourceId' => $this->resourceId === null ? null : (int)$this->resourceId,
		];
	}
}

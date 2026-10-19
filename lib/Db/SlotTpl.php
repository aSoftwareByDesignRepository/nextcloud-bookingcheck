<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

class SlotTpl extends Entity
{
	protected $offeringId;
	protected $durationMin;
	protected $capPerSlot;
	protected $bufferMin;
	protected $leadMin;
	protected $horizonDays;
	protected $weeklyRrule;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('offeringId', 'integer');
		$this->addType('durationMin', 'integer');
		$this->addType('capPerSlot', 'integer');
		$this->addType('bufferMin', 'integer');
		$this->addType('leadMin', 'integer');
		$this->addType('horizonDays', 'integer');
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
		];
	}
}

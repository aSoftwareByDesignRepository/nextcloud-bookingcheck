<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

class AuditEntry extends Entity
{
	protected $orderId;
	protected $offeringId;
	protected $actor;
	protected $action;
	protected $fromState;
	protected $toState;
	protected $meta;
	protected $prevHash;
	protected $rowHash;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('orderId', 'integer');
		$this->addType('offeringId', 'integer');
		$this->addType('createdAt', 'datetime_immutable');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'orderId' => $this->orderId === null ? null : (int)$this->orderId,
			'offeringId' => $this->offeringId === null ? null : (int)$this->offeringId,
			'actor' => $this->actor,
			'action' => $this->action,
			'fromState' => $this->fromState,
			'toState' => $this->toState,
			'meta' => $this->meta,
			'prevHash' => $this->prevHash,
			'rowHash' => $this->rowHash,
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
		];
	}
}

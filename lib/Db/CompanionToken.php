<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

class CompanionToken extends Entity
{
	protected $tokenHash;
	protected $label;
	protected $role;
	protected $offeringIds;
	protected $createdBy;
	protected $createdAt;
	protected $lastSeenAt;
	protected $revokedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('createdAt', 'datetime_immutable');
		$this->addType('lastSeenAt', 'datetime_immutable');
		$this->addType('revokedAt', 'datetime_immutable');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'tokenHash' => $this->tokenHash,
			'label' => $this->label,
			'role' => $this->role,
			'offeringIds' => $this->offeringIds,
			'createdBy' => $this->createdBy,
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
			'lastSeenAt' => $this->lastSeenAt === null ? null : $this->lastSeenAt->format(\DateTimeInterface::ATOM),
			'revokedAt' => $this->revokedAt === null ? null : $this->revokedAt->format(\DateTimeInterface::ATOM),
		];
	}
}

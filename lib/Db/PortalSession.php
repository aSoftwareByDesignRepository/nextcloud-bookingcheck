<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

class PortalSession extends Entity
{
	protected $tokenHash;
	protected $email;
	protected $createdAt;
	protected $expiresAt;
	protected $absExpiresAt;
	protected $lastSeenAt;
	protected $uaShort;
	protected $revokedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('createdAt', 'datetime_immutable');
		$this->addType('expiresAt', 'datetime_immutable');
		$this->addType('absExpiresAt', 'datetime_immutable');
		$this->addType('lastSeenAt', 'datetime_immutable');
		$this->addType('revokedAt', 'datetime_immutable');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'tokenHash' => $this->tokenHash,
			'email' => $this->email,
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
			'expiresAt' => $this->expiresAt === null ? null : $this->expiresAt->format(\DateTimeInterface::ATOM),
			'absExpiresAt' => $this->absExpiresAt === null ? null : $this->absExpiresAt->format(\DateTimeInterface::ATOM),
			'lastSeenAt' => $this->lastSeenAt === null ? null : $this->lastSeenAt->format(\DateTimeInterface::ATOM),
			'uaShort' => $this->uaShort,
			'revokedAt' => $this->revokedAt === null ? null : $this->revokedAt->format(\DateTimeInterface::ATOM),
		];
	}
}

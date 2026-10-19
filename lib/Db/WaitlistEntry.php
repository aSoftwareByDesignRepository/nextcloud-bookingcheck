<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

class WaitlistEntry extends Entity
{
	protected $targetType;
	protected $targetId;
	protected $email;
	protected $locale;
	protected $state;
	protected $offerExpiresAt;
	protected $claimTokenHash;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('targetId', 'integer');
		$this->addType('offerExpiresAt', 'datetime_immutable');
		$this->addType('createdAt', 'datetime_immutable');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'targetType' => $this->targetType,
			'targetId' => (int)$this->targetId,
			'email' => $this->email,
			'locale' => $this->locale,
			'state' => $this->state,
			'offerExpiresAt' => $this->offerExpiresAt === null ? null : $this->offerExpiresAt->format(\DateTimeInterface::ATOM),
			'claimTokenHash' => $this->claimTokenHash,
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
		];
	}
}

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

class Otp extends Entity
{
	protected $email;
	protected $codeHash;
	protected $magicTokenHash;
	protected $attempts;
	protected $createdAt;
	protected $expiresAt;
	protected $usedAt;
	protected $ipHash;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('attempts', 'integer');
		$this->addType('createdAt', 'datetime_immutable');
		$this->addType('expiresAt', 'datetime_immutable');
		$this->addType('usedAt', 'datetime_immutable');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'email' => $this->email,
			'codeHash' => $this->codeHash,
			'magicTokenHash' => $this->magicTokenHash,
			'attempts' => (int)$this->attempts,
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
			'expiresAt' => $this->expiresAt === null ? null : $this->expiresAt->format(\DateTimeInterface::ATOM),
			'usedAt' => $this->usedAt === null ? null : $this->usedAt->format(\DateTimeInterface::ATOM),
			'ipHash' => $this->ipHash,
		];
	}
}

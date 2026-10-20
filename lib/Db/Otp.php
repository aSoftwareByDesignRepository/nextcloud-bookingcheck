<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the `bc_otp` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getEmail()
 * @method void setEmail(string $email)
 * @method string getCodeHash()
 * @method void setCodeHash(string $codeHash)
 * @method string|null getMagicTokenHash()
 * @method void setMagicTokenHash(?string $magicTokenHash)
 * @method int getAttempts()
 * @method void setAttempts(int $attempts)
 * @method \DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(\DateTimeImmutable $createdAt)
 * @method \DateTimeImmutable getExpiresAt()
 * @method void setExpiresAt(\DateTimeImmutable $expiresAt)
 * @method \DateTimeImmutable|null getUsedAt()
 * @method void setUsedAt(?\DateTimeImmutable $usedAt)
 * @method string|null getIpHash()
 * @method void setIpHash(?string $ipHash)
 */
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

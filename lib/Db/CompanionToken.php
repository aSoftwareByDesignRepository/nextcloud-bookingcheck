<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the `bc_companion` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getTokenHash()
 * @method void setTokenHash(string $tokenHash)
 * @method string getLabel()
 * @method void setLabel(string $label)
 * @method string getRole()
 * @method void setRole(string $role)
 * @method string|null getOfferingIds()
 * @method void setOfferingIds(?string $offeringIds)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method \DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(\DateTimeImmutable $createdAt)
 * @method \DateTimeImmutable|null getLastSeenAt()
 * @method void setLastSeenAt(?\DateTimeImmutable $lastSeenAt)
 * @method \DateTimeImmutable|null getRevokedAt()
 * @method void setRevokedAt(?\DateTimeImmutable $revokedAt)
 * @method string|null getPushToken()
 * @method void setPushToken(?string $pushToken)
 * @method string|null getPushKind()
 * @method void setPushKind(?string $pushKind)
 * @method string|null getLocale()
 * @method void setLocale(?string $locale)
 */
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
	protected $pushToken;
	protected $pushKind;
	protected $locale;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('createdAt', 'datetime_immutable');
		$this->addType('lastSeenAt', 'datetime_immutable');
		$this->addType('revokedAt', 'datetime_immutable');
		$this->addType('pushToken', 'string');
		$this->addType('pushKind', 'string');
		$this->addType('locale', 'string');
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

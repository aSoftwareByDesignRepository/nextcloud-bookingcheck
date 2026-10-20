<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the `bc_waitlist` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getTargetType()
 * @method void setTargetType(string $targetType)
 * @method int getTargetId()
 * @method void setTargetId(int $targetId)
 * @method string getEmail()
 * @method void setEmail(string $email)
 * @method string getLocale()
 * @method void setLocale(string $locale)
 * @method string getState()
 * @method void setState(string $state)
 * @method \DateTimeImmutable|null getOfferExpiresAt()
 * @method void setOfferExpiresAt(?\DateTimeImmutable $offerExpiresAt)
 * @method string|null getClaimTokenHash()
 * @method void setClaimTokenHash(?string $claimTokenHash)
 * @method int|null getResourceId()
 * @method void setResourceId(?int $resourceId)
 * @method \DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(\DateTimeImmutable $createdAt)
 */
class WaitlistEntry extends Entity
{
	protected $targetType;
	protected $targetId;
	protected $email;
	protected $locale;
	protected $state;
	protected $offerExpiresAt;
	protected $claimTokenHash;
	protected $resourceId;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('targetId', 'integer');
		$this->addType('resourceId', 'integer');
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
			'resourceId' => $this->resourceId === null ? null : (int)$this->resourceId,
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
		];
	}
}

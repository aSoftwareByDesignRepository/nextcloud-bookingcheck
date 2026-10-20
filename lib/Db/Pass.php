<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the `bc_pass` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getOfferingId()
 * @method void setOfferingId(int $offeringId)
 * @method int getOrderId()
 * @method void setOrderId(int $orderId)
 * @method string getTokenHash()
 * @method void setTokenHash(string $tokenHash)
 * @method string getHolderName()
 * @method void setHolderName(string $holderName)
 * @method string getHolderEmail()
 * @method void setHolderEmail(string $holderEmail)
 * @method \DateTimeImmutable getValidFrom()
 * @method void setValidFrom(\DateTimeImmutable $validFrom)
 * @method \DateTimeImmutable getValidUntil()
 * @method void setValidUntil(\DateTimeImmutable $validUntil)
 * @method int|null getUsesMax()
 * @method void setUsesMax(?int $usesMax)
 * @method int getUses()
 * @method void setUses(int $uses)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method \DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(\DateTimeImmutable $createdAt)
 */
class Pass extends Entity
{
	protected $offeringId;
	protected $orderId;
	protected $tokenHash;
	protected $holderName;
	protected $holderEmail;
	protected $validFrom;
	protected $validUntil;
	protected $usesMax;
	protected $uses;
	protected $status;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('offeringId', 'integer');
		$this->addType('orderId', 'integer');
		$this->addType('usesMax', 'integer');
		$this->addType('uses', 'integer');
		$this->addType('validFrom', 'datetime_immutable');
		$this->addType('validUntil', 'datetime_immutable');
		$this->addType('createdAt', 'datetime_immutable');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'offeringId' => (int)$this->offeringId,
			'orderId' => (int)$this->orderId,
			'holderName' => $this->holderName,
			'validFrom' => $this->validFrom === null ? null : $this->validFrom->format(\DateTimeInterface::ATOM),
			'validUntil' => $this->validUntil === null ? null : $this->validUntil->format(\DateTimeInterface::ATOM),
			'usesMax' => $this->usesMax === null ? null : (int)$this->usesMax,
			'uses' => (int)$this->uses,
			'status' => $this->status,
		];
	}
}

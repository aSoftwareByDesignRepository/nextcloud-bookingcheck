<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/** NH-28 recurring-validity pass — bearer token BCK1P-… admitted at the door. */
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

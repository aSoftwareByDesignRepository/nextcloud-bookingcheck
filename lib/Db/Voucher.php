<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

class Voucher extends Entity
{
	protected $code;
	protected $currency;
	protected $creditCents;
	protected $remainingCents;
	protected $validUntil;
	protected $maxUses;
	protected $uses;
	protected $source;
	protected $sourceOrderId;
	protected $active;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('creditCents', 'integer');
		$this->addType('remainingCents', 'integer');
		$this->addType('validUntil', 'datetime_immutable');
		$this->addType('maxUses', 'integer');
		$this->addType('uses', 'integer');
		$this->addType('sourceOrderId', 'integer');
		$this->addType('active', 'integer');
		$this->addType('createdAt', 'datetime_immutable');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'code' => $this->code,
			'currency' => $this->currency,
			'creditCents' => (int)$this->creditCents,
			'remainingCents' => (int)$this->remainingCents,
			'validUntil' => $this->validUntil === null ? null : $this->validUntil->format(\DateTimeInterface::ATOM),
			'maxUses' => (int)$this->maxUses,
			'uses' => (int)$this->uses,
			'source' => $this->source,
			'sourceOrderId' => $this->sourceOrderId === null ? null : (int)$this->sourceOrderId,
			'active' => (int)$this->active,
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
		];
	}
}

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

class Promo extends Entity
{
	protected $code;
	protected $kind;
	protected $percentBps;
	protected $amountCents;
	protected $scope;
	protected $currency;
	protected $validFrom;
	protected $validUntil;
	protected $maxUses;
	protected $uses;
	protected $active;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('percentBps', 'integer');
		$this->addType('amountCents', 'integer');
		$this->addType('validFrom', 'datetime_immutable');
		$this->addType('validUntil', 'datetime_immutable');
		$this->addType('maxUses', 'integer');
		$this->addType('uses', 'integer');
		$this->addType('active', 'integer');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'code' => $this->code,
			'kind' => $this->kind,
			'percentBps' => $this->percentBps === null ? null : (int)$this->percentBps,
			'amountCents' => $this->amountCents === null ? null : (int)$this->amountCents,
			'scope' => $this->scope,
			'currency' => $this->currency,
			'validFrom' => $this->validFrom === null ? null : $this->validFrom->format(\DateTimeInterface::ATOM),
			'validUntil' => $this->validUntil === null ? null : $this->validUntil->format(\DateTimeInterface::ATOM),
			'maxUses' => $this->maxUses === null ? null : (int)$this->maxUses,
			'uses' => (int)$this->uses,
			'active' => (int)$this->active,
		];
	}
}

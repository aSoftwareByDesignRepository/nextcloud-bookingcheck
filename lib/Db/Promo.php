<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the `bc_promo` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getCode()
 * @method void setCode(string $code)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method int|null getPercentBps()
 * @method void setPercentBps(?int $percentBps)
 * @method int|null getAmountCents()
 * @method void setAmountCents(?int $amountCents)
 * @method string getScope()
 * @method void setScope(string $scope)
 * @method string|null getCurrency()
 * @method void setCurrency(?string $currency)
 * @method \DateTimeImmutable|null getValidFrom()
 * @method void setValidFrom(?\DateTimeImmutable $validFrom)
 * @method \DateTimeImmutable|null getValidUntil()
 * @method void setValidUntil(?\DateTimeImmutable $validUntil)
 * @method int|null getMaxUses()
 * @method void setMaxUses(?int $maxUses)
 * @method int getUses()
 * @method void setUses(int $uses)
 * @method int getActive()
 * @method void setActive(int $active)
 */
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

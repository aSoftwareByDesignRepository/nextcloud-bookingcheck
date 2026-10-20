<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the `bc_price_cat` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getOfferingId()
 * @method void setOfferingId(int $offeringId)
 * @method string getName()
 * @method void setName(string $name)
 * @method int getPriceCents()
 * @method void setPriceCents(int $priceCents)
 * @method int getSort()
 * @method void setSort(int $sort)
 * @method int|null getMaxPerOrder()
 * @method void setMaxPerOrder(?int $maxPerOrder)
 * @method int getActive()
 * @method void setActive(int $active)
 * @method \DateTimeImmutable|null getSaleFrom()
 * @method void setSaleFrom(?\DateTimeImmutable $saleFrom)
 * @method \DateTimeImmutable|null getSaleTo()
 * @method void setSaleTo(?\DateTimeImmutable $saleTo)
 */
class PriceCat extends Entity
{
	protected $offeringId;
	protected $name;
	protected $priceCents;
	protected $sort;
	protected $maxPerOrder;
	protected $active;
	protected $saleFrom;
	protected $saleTo;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('offeringId', 'integer');
		$this->addType('priceCents', 'integer');
		$this->addType('sort', 'integer');
		$this->addType('maxPerOrder', 'integer');
		$this->addType('active', 'integer');
		$this->addType('saleFrom', 'datetime_immutable');
		$this->addType('saleTo', 'datetime_immutable');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'offeringId' => (int)$this->offeringId,
			'name' => $this->name,
			'priceCents' => (int)$this->priceCents,
			'sort' => (int)$this->sort,
			'maxPerOrder' => $this->maxPerOrder === null ? null : (int)$this->maxPerOrder,
			'active' => (int)$this->active,
		];
	}
}

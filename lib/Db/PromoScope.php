<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the `bc_promo_scope` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getPromoId()
 * @method void setPromoId(int $promoId)
 * @method int getOfferingId()
 * @method void setOfferingId(int $offeringId)
 */
class PromoScope extends Entity
{
	protected $promoId;
	protected $offeringId;

	public function __construct()
	{
		$this->addType('promoId', 'integer');
		$this->addType('offeringId', 'integer');
	}

	public function jsonSerialize(): array
	{
		return [
			'promoId' => (int)$this->promoId,
			'offeringId' => (int)$this->offeringId,
		];
	}
}

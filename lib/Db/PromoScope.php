<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/** Composite-PK join row: (promo_id, offering_id). No surrogate id. */
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

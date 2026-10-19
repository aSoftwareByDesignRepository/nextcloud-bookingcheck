<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<PromoScope> */
class PromoScopeMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'bc_promo_scope', PromoScope::class);
	}

	/** @return list<int> offering ids in scope for a promo */
	public function offeringIdsForPromo(int $promoId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('offering_id')->from($this->getTableName())
			->where($qb->expr()->eq('promo_id', $qb->createNamedParameter($promoId)));
		return array_map('intval', $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
	}

	public function inScope(int $promoId, int $offeringId): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('promo_id')->from($this->getTableName())
			->where($qb->expr()->eq('promo_id', $qb->createNamedParameter($promoId)))
			->andWhere($qb->expr()->eq('offering_id', $qb->createNamedParameter($offeringId)));
		return $qb->executeQuery()->fetchOne() !== false;
	}

	public function add(int $promoId, int $offeringId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->insert($this->getTableName())
			->setValue('promo_id', $qb->createNamedParameter($promoId))
			->setValue('offering_id', $qb->createNamedParameter($offeringId));
		$qb->executeStatement();
	}

	public function removeAllForPromo(int $promoId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('promo_id', $qb->createNamedParameter($promoId)));
		$qb->executeStatement();
	}
}

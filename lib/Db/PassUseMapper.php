<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<PassUse> */
class PassUseMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'bc_pass_use', PassUse::class);
	}

	public function record(int $passId, string $targetType, int $targetId, string $device): bool
	{
		$e = new PassUse();
		$e->setPassId($passId);
		$e->setTargetType($targetType);
		$e->setTargetId($targetId);
		$e->setDevice($device);
		$e->setCreatedAt(new \DateTimeImmutable());
		try {
			$this->insert($e);
			return true;
		} catch (\Throwable) {
			return false; // unique (pass,target) — already admitted there
		}
	}

	public function usedAt(int $passId, string $targetType, int $targetId): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($this->getTableName())
			->where($qb->expr()->eq('pass_id', $qb->createNamedParameter($passId)))
			->andWhere($qb->expr()->eq('target_type', $qb->createNamedParameter($targetType)))
			->andWhere($qb->expr()->eq('target_id', $qb->createNamedParameter($targetId)))
			->setMaxResults(1);
		return $qb->executeQuery()->fetchOne() !== false;
	}
}

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<DownloadLog> */
class DownloadLogMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'bc_download', DownloadLog::class);
	}

	public function record(int $materialId, ?int $orderId, ?int $ticketId, string $actor): void
	{
		$e = new DownloadLog();
		$e->setMaterialId($materialId);
		$e->setOrderId($orderId);
		$e->setTicketId($ticketId);
		$e->setActor($actor);
		$e->setCreatedAt(new \DateTimeImmutable());
		$this->insert($e);
	}

	/** @return array<int,int> material_id ⇒ download count */
	/** @param list<int> $materialIds */
	public function countsForOffering(array $materialIds): array
	{
		if ($materialIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('material_id')
			->selectAlias($qb->func()->count('id'), 'cnt')
			->from($this->getTableName())
			->where($qb->expr()->in('material_id',
				$qb->createNamedParameter(array_map('intval', $materialIds), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
			->groupBy('material_id');
		$out = [];
		foreach ($qb->executeQuery()->fetchAll() as $r) {
			$out[(int)$r['material_id']] = (int)$r['cnt'];
		}
		return $out;
	}
}

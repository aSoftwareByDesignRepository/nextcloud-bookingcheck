<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<AbuseReport> */
class AbuseReportMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'bc_abuse', AbuseReport::class);
	}

	public function find(int $id): ?AbuseReport
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<AbuseReport> open reports, oldest first */
	public function listOpen(int $limit = 200): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter('open')))
			->orderBy('created_at', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/** @return list<AbuseReport> */
	public function listForUpload(int $uploadId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('upload_id', $qb->createNamedParameter($uploadId)))
			->orderBy('created_at', 'DESC');
		return $this->findEntities($qb);
	}
}

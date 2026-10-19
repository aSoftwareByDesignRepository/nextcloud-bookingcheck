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

/** @template-extends QBMapper<Upload> */
class UploadMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'bc_upload', Upload::class);
	}

	public function find(int $id): ?Upload
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

	/** Live bytes held against an offering's quota. */
	public function liveBytesForOffering(int $offeringId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->sum('size_bytes'))->from($this->getTableName())
			->where($qb->expr()->eq('offering_id', $qb->createNamedParameter($offeringId)))
			->andWhere($qb->expr()->isNull('deleted_at'));
		$v = $qb->executeQuery()->fetchOne();
		return $v === false || $v === null ? 0 : (int)$v;
	}

	/** @return list<Upload> live uploads for an offering (admin moderation) */
	public function forOffering(int $offeringId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('offering_id', $qb->createNamedParameter($offeringId)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->orderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * Uploads the viewer may see: their own (email match) plus shared ones.
	 * $occurrences are ['type'=>..,'id'=>..] pairs of the viewer's paid seat
	 * lines — shared matching joins through the uploader's stored line id,
	 * which carries the same (type,id) pair (no cross-type id collision).
	 *
	 * 'offering' scope = every live upload on the offering; 'occurrence'
	 * scope = only uploads whose seat line shares an occurrence with the
	 * viewer's (plus always the viewer's own).
	 *
	 * @param list<array{type:string,id:int}> $occurrences
	 * @return list<Upload>
	 */
	public function visibleTo(int $offeringId, string $viewerEmail, array $occurrences, string $scope): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('u.*')->from($this->getTableName(), 'u')
			->where($qb->expr()->eq('u.offering_id', $qb->createNamedParameter($offeringId)))
			->andWhere($qb->expr()->isNull('u.deleted_at'));
		if ($scope === 'occurrence') {
			$own = $qb->expr()->eq('u.uploader_email', $qb->createNamedParameter($viewerEmail));
			if ($occurrences === []) {
				$qb->andWhere($own);
			} else {
				$qb->leftJoin('u', 'bc_order_line', 'ul', $qb->expr()->eq('ul.id', 'u.occurrence_id'));
				$pairOr = $qb->expr()->orX();
				foreach ($occurrences as $occ) {
					$pairOr->add($qb->expr()->andX(
						$qb->expr()->eq('ul.target_type', $qb->createNamedParameter($occ['type'])),
						$qb->expr()->eq('ul.target_id', $qb->createNamedParameter($occ['id']))
					));
				}
				$qb->andWhere($qb->expr()->orX($own, $pairOr));
			}
		}
		// 'offering' scope: base filter suffices — viewer eligibility
		// (paid order on this offering) is proven upstream.
		$qb->orderBy('u.id', 'DESC');
		return $this->findEntities($qb);
	}
}

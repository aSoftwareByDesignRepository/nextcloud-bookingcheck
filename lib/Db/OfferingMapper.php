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

/** @template-extends QBMapper<Offering> */
class OfferingMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'bc_offering', Offering::class);
	}

	public function find(int $id): ?Offering
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

	/** Row lock for CAS updates — call inside an open transaction. */
	public function findForUpdate(int $id): ?Offering
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->forUpdate();
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<Offering> */
	public function findAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function findBySlug(string $slug): ?Offering
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('slug', $qb->createNamedParameter($slug)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<Offering> published, publicly listable offerings */
	public function findPublished(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter('published')))
			->orderBy('title', 'ASC');
		return $this->findEntities($qb);
	}

	/** True when any order ever referenced this offering — legal records. */
	public function hasOrders(int $offeringId): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('bc_order')
			->where($qb->expr()->eq('offering_id', $qb->createNamedParameter($offeringId)));
		return (int)$qb->executeQuery()->fetchOne() > 0;
	}

	/**
	 * Remove every offering-owned config row before the offering itself.
	 * Call only when hasOrders() is false — sold offerings must be archived,
	 * never deleted (orders would dangle). Runs in the caller's transaction.
	 *
	 * Left alone on purpose: bc_audit (append-only history) and
	 * bc_promo / bc_voucher / bc_material_lib (global, not offering-owned).
	 */
	public function deleteChildren(int $offeringId): void
	{
		// waitlist rows key on target ids — collect them first
		$targetIds = [];
		foreach (['bc_event', 'bc_series', 'bc_slot'] as $t) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')->from($t)
				->where($qb->expr()->eq('offering_id', $qb->createNamedParameter($offeringId)));
			$targetIds[$t] = array_map('intval',
				$qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
		}
		if ($targetIds['bc_slot'] !== []) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('bc_slot_res')
				->where($qb->expr()->in('slot_id', $qb->createNamedParameter(
					$targetIds['bc_slot'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
		$wl = [];
		foreach (['event' => 'bc_event', 'series' => 'bc_series', 'slot' => 'bc_slot'] as $type => $t) {
			if ($targetIds[$t] !== []) {
				$wl[] = [$type, $targetIds[$t]];
			}
		}
		foreach ($wl as [$type, $ids]) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('bc_waitlist')
				->where($qb->expr()->eq('target_type', $qb->createNamedParameter($type)))
				->andWhere($qb->expr()->in('target_id', $qb->createNamedParameter(
					$ids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
		// offering-scoped tables — plain offering_id deletes
		foreach (['bc_event', 'bc_series', 'bc_slot', 'bc_slot_tpl', 'bc_price_cat',
			'bc_addon', 'bc_resource', 'bc_question', 'bc_material',
			'bc_promo_scope', 'bc_broadcast'] as $t) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($t)
				->where($qb->expr()->eq('offering_id', $qb->createNamedParameter($offeringId)))
				->executeStatement();
		}
	}

}

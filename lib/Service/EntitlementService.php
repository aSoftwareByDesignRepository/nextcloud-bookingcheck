<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCP\IDBConnection;

/**
 * Portal entitlement — evaluated PER REQUEST, never snapshotted (§9.8).
 *
 * A buyer is entitled to a material when ANY order line joins buyer_email
 * OR ticket attendee_email to the material's offering (within scope and
 * availability window) on a currently-paid order. Refund/cancellation
 * kills access on the next request.
 */
class EntitlementService
{
	public function __construct(private readonly IDBConnection $db)
	{
	}

	/**
	 * Paid order ids for an email — union of "is the buyer" and
	 * "is an attendee on any ticket of the order".
	 * @return list<int>
	 */
	public function entitledOrders(string $email): array
	{
		$email = strtolower(trim($email));

		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('bc_order')
			->where($qb->expr()->eq('status', $qb->createNamedParameter('paid')))
			->andWhere($qb->expr()->eq('buyer_email', $qb->createNamedParameter($email)));
		$ids = array_map('intval', $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));

		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('ol.order_id')
			->from('bc_ticket', 't')
			->innerJoin('t', 'bc_order_line', 'ol', $qb->expr()->eq('ol.id', 't.line_id'))
			->innerJoin('ol', 'bc_order', 'o', $qb->expr()->eq('o.id', 'ol.order_id'))
			->where($qb->expr()->eq('o.status', $qb->createNamedParameter('paid')))
			->andWhere($qb->expr()->eq('t.attendee_email', $qb->createNamedParameter($email)));
		$ids = array_merge($ids, array_map('intval', $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN)));

		$ids = array_values(array_unique($ids));
		rsort($ids);
		if ($ids === []) {
			return [];
		}
		// hydrate for display: ref + status + offering title (via the order's
		// offering_id — a cancelled/refunded order keeps its last title)
		$qb = $this->db->getQueryBuilder();
		$qb->select('o.id', 'o.ref', 'o.status', 'of.title', 'of.talk_room')
			->from('bc_order', 'o')
			->leftJoin('o', 'bc_offering', 'of', $qb->expr()->eq('of.id', 'o.offering_id'))
			->where($qb->expr()->in('o.id', $qb->createNamedParameter($ids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('o.id', 'DESC');
		return $qb->executeQuery()->fetchAll();
	}

	/** Materials the email may see right now. */
	public function entitledMaterials(string $email): array
	{
		$email = strtolower(trim($email));
		$now = new \DateTimeImmutable();
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('m.*')
			->from('bc_material', 'm')
			->innerJoin('m', 'bc_order_line', 'ol', $qb->expr()->andX(
				$qb->expr()->eq('ol.target_id', 'm.target_id'),
				$qb->expr()->eq('ol.target_type', 'm.target_type'),
			))
			->innerJoin('ol', 'bc_order', 'o', $qb->expr()->eq('o.id', 'ol.order_id'))
			->leftJoin('ol', 'bc_ticket', 't', $qb->expr()->eq('t.line_id', 'ol.id'))
			->where($qb->expr()->eq('o.status', $qb->createNamedParameter('paid')))
			->andWhere($qb->expr()->orX(
				$qb->expr()->eq('o.buyer_email', $qb->createNamedParameter($email)),
				$qb->expr()->eq('t.attendee_email', $qb->createNamedParameter($email)),
			))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('m.avail_from'),
				$qb->expr()->lte('m.avail_from', $qb->createNamedParameter($now, 'datetime')),
			))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('m.avail_to'),
				$qb->expr()->gte('m.avail_to', $qb->createNamedParameter($now, 'datetime')),
			));

		// also offering-wide materials (target_id NULL) — separate union
		$qb2 = $this->db->getQueryBuilder();
		$qb2->selectDistinct('m.*')
			->from('bc_material', 'm')
			->innerJoin('m', 'bc_order', 'o', $qb2->expr()->eq('o.offering_id', 'm.offering_id'))
			->leftJoin('o', 'bc_order_line', 'ol', $qb2->expr()->eq('ol.order_id', 'o.id'))
			->leftJoin('ol', 'bc_ticket', 't', $qb2->expr()->eq('t.line_id', 'ol.id'))
			->where($qb2->expr()->eq('o.status', $qb2->createNamedParameter('paid')))
			->andWhere($qb2->expr()->isNull('m.target_id'))
			->andWhere($qb2->expr()->orX(
				$qb2->expr()->eq('o.buyer_email', $qb2->createNamedParameter($email)),
				$qb2->expr()->eq('t.attendee_email', $qb2->createNamedParameter($email)),
			))
			->andWhere($qb2->expr()->orX(
				$qb2->expr()->isNull('m.avail_from'),
				$qb2->expr()->lte('m.avail_from', $qb2->createNamedParameter($now, 'datetime')),
			))
			->andWhere($qb2->expr()->orX(
				$qb2->expr()->isNull('m.avail_to'),
				$qb2->expr()->gte('m.avail_to', $qb2->createNamedParameter($now, 'datetime')),
			));

		$rows = array_merge(
			$qb->executeQuery()->fetchAll(),
			$qb2->executeQuery()->fetchAll(),
		);
		$seen = [];
		return array_values(array_filter($rows, function ($r) use (&$seen) {
			if (isset($seen[$r['id']])) {
				return false;
			}
			$seen[$r['id']] = true;
			return true;
		}));
	}

	/** Can this email download material $id right now? */
	public function canAccessMaterial(string $email, int $materialId): bool
	{
		foreach ($this->entitledMaterials($email) as $m) {
			if ((int)$m['id'] === $materialId) {
				return true;
			}
		}
		return false;
	}
}

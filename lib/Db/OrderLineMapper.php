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

/** @template-extends QBMapper<OrderLine> */
class OrderLineMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'bc_order_line', OrderLine::class);
	}

	public function find(int $id): ?OrderLine
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
	public function findForUpdate(int $id): ?OrderLine
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

	/** @return list<OrderLine> */
	public function findAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<OrderLine> */
	public function findByOrder(int $orderId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('order_id', $qb->createNamedParameter($orderId)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** Latest occurrence end among the order's targets (manage-link TTL). */
	public function lastBookedEnd(int $orderId): ?\DateTimeImmutable
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('l.target_type', 'l.target_id')->from($this->getTableName(), 'l')
			->where($qb->expr()->eq('l.order_id', $qb->createNamedParameter($orderId)))
			->andWhere($qb->expr()->eq('l.line_kind', $qb->createNamedParameter('seat')));
		$max = null;
		foreach ($qb->executeQuery()->fetchAll() as $l) {
			$t = match ($l['target_type']) {
				'event' => 'bc_event', 'slot' => 'bc_slot',
				'series' => 'bc_event', // series ends at its last event
				default => null,
			};
			if ($t === null) {
				continue;
			}
			$q = $this->db->getQueryBuilder();
			$q->select('ends_at')->from($t)
				->where($l['target_type'] === 'series'
					? $q->expr()->eq('series_id', $q->createNamedParameter((int)$l['target_id']))
					: $q->expr()->eq('id', $q->createNamedParameter((int)$l['target_id'])))
				->orderBy('ends_at', 'DESC')->setMaxResults(1);
			$end = $q->executeQuery()->fetchOne();
			if ($end !== false) {
				$d = new \DateTimeImmutable((string)$end, new \DateTimeZone('UTC'));
				if ($max === null || $d > $max) {
					$max = $d;
				}
			}
		}
		return $max;
	}
}

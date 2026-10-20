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

/** @template-extends QBMapper<Ticket> */
class TicketMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'bc_ticket', Ticket::class);
	}

	public function find(int $id): ?Ticket
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
	public function findForUpdate(int $id): ?Ticket
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

	/** @return list<Ticket> */
	public function findAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function findByQrHash(string $hash): ?Ticket
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('qr_token_hash', $qb->createNamedParameter($hash)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function countForLine(int $lineId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))->from($this->getTableName())
			->where($qb->expr()->eq('line_id', $qb->createNamedParameter($lineId)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** @return list<Ticket> */
	public function findByLine(int $lineId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('line_id', $qb->createNamedParameter($lineId)))
			->orderBy('seat_no', 'ASC');
		return $this->findEntities($qb);
	}

	/** Live tickets of an order — the portal order page renders these. */
	public function findForOrder(int $orderId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('t.*')->from('bc_ticket', 't')
			->innerJoin('t', 'bc_order_line', 'ol', $qb->expr()->eq('ol.id', 't.line_id'))
			->where($qb->expr()->eq('ol.order_id', $qb->createNamedParameter($orderId)))
			->andWhere($qb->expr()->isNull('t.revoked_at'))
			->orderBy('ol.id', 'ASC')->addOrderBy('t.seat_no', 'ASC');
		return $this->findEntities($qb);
	}

}

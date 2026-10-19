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

/** @template-extends QBMapper<Payment> */
class PaymentMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'bc_payment', Payment::class);
	}

	public function find(int $id): ?Payment
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
	public function findForUpdate(int $id): ?Payment
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

	/** @return list<Payment> */
	public function findAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function findByPspRef(string $provider, string $pspRef): ?Payment
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('provider', $qb->createNamedParameter($provider)))
			->andWhere($qb->expr()->eq('psp_ref', $qb->createNamedParameter($pspRef)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function findByOrder(int $orderId): ?Payment
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('order_id', $qb->createNamedParameter($orderId)))
			->orderBy('id', 'DESC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** Any other LIVE session for this order? A failed/expired event on
	 *  session A must not kill an order whose session B is still payable. */
	public function hasOpenSessionForOrder(int $orderId, int $exceptPaymentId): bool
	{
		$qb = $this->db->getQueryBuilder();
		return (int)$qb->select($qb->func()->count('id'))->from($this->getTableName())
			->where($qb->expr()->eq('order_id', $qb->createNamedParameter($orderId)))
			->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($exceptPaymentId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('open')))
			->executeQuery()->fetchOne() > 0;
	}

	/** The SETTLED session for an order — refund paths must use this, not
	 *  findByOrder: a retried checkout leaves a newer 'open' row on top,
	 *  and refunding a session that was never charged fails at the PSP. */
	public function findPaidByOrder(int $orderId): ?Payment
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('order_id', $qb->createNamedParameter($orderId)))
			->andWhere($qb->expr()->in('status',
				$qb->createNamedParameter(['paid', 'refunded'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('id', 'DESC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function updateStatus(int $id, string $status, string $rawStatus, ?string $eventId = null): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter($status))
			->set('raw_status', $qb->createNamedParameter($rawStatus))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		if ($eventId !== null) {
			$qb->set('psp_event_id', $qb->createNamedParameter($eventId));
		}
		$qb->executeStatement();
	}

	public function markDisputed(int $id): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('disputed', $qb->createNamedParameter(1))
			->set('status', $qb->createNamedParameter('chargeback'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		$qb->executeStatement();
	}
}

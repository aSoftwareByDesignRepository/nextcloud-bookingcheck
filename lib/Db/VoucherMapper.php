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

/** @template-extends QBMapper<Voucher> */
class VoucherMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'bc_voucher', Voucher::class);
	}

	public function find(int $id): ?Voucher
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
	public function findForUpdate(int $id): ?Voucher
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

	/** @return list<Voucher> */
	public function findAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function findByCode(string $code): ?Voucher
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('code', $qb->createNamedParameter($code)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Atomic redemption: succeeds only when the row still has credit, uses
	 * left, is active and unexpired. affected-rows=1 ⇒ this caller won.
	 */
	public function redeem(int $id, int $cents): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('remaining_cents', $qb->createFunction('remaining_cents - ' . $cents))
			->set('uses', $qb->createFunction('uses + 1'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(1)))
			->andWhere($qb->expr()->gte('remaining_cents', $qb->createNamedParameter($cents)))
			->andWhere($qb->expr()->lt('uses', $qb->createFunction('max_uses')))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('valid_until'),
				$qb->expr()->gte('valid_until', $qb->createNamedParameter(new \DateTime(), 'datetime')),
			));
		return $qb->executeStatement() === 1;
	}

	/** Refund/expiry path: restore credit and un-count the use. */
	public function restore(int $id, int $cents): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('remaining_cents', $qb->createFunction('remaining_cents + ' . $cents))
			->set('uses', $qb->createFunction('CASE WHEN uses > 0 THEN uses - 1 ELSE 0 END'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		$qb->executeStatement();
	}

	/**
	 * Post-payment refund path: return the redeemed credit WITHOUT
	 * un-counting the use — the redemption genuinely happened (a voucher
	 * is stored value; refunding the order must not burn the buyer's
	 * balance). Capped at the issued credit so a double-call can never
	 * mint value out of thin air.
	 */
	public function restoreCredit(int $id, int $cents): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('remaining_cents', $qb->createFunction(
				'CASE WHEN remaining_cents + ' . $cents . ' > credit_cents'
				. ' THEN credit_cents ELSE remaining_cents + ' . $cents . ' END'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			// belt & braces: only an already-redeemed voucher can be topped up
			->andWhere($qb->expr()->gt('uses', $qb->createNamedParameter(0)));
		$qb->executeStatement();
	}
}

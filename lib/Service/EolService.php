<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\Db\OfferingMapper;
use OCP\IDBConnection;

/**
 * Offering end-of-life (§9.11).
 *
 * none → closing → closed → warned → archived|purged. Every transition
 * is idempotent + audited; each offering is row-locked while processed.
 * PURGE anonymizes buyer/attendee PII but NEVER touches invoice,
 * payment, amounts, or audit (GoBD, NN-19).
 */
class EolService
{
	public function __construct(
		private readonly OfferingMapper $offerings,
		private readonly AuditService $audit,
		private readonly IDBConnection $db,
	) {
	}

	public function processDue(): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('bc_offering')
			->where($qb->expr()->neq('eol_state', $qb->createNamedParameter('purged')))
			->andWhere($qb->expr()->neq('eol_state', $qb->createNamedParameter('none')))
			->andWhere($qb->expr()->isNotNull('eol_action_at'))
			->andWhere($qb->expr()->lte('eol_action_at',
				$qb->createNamedParameter(new \DateTime(), 'datetime')))
			->setMaxResults(50);
		foreach ($qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN) as $id) {
			$this->processOne((int)$id);
		}
	}

	private function processOne(int $id): void
	{
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from('bc_offering')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
				->forUpdate();
			$row = $qb->executeQuery()->fetch();
			if ($row === false) {
				$this->db->commit();
				return;
			}
			$next = match ($row['eol_state']) {
				'closing' => 'closed',
				'closed' => 'warned',
				'warned' => 'purged',
				default => null,
			};
			if ($next === null) {
				$this->db->commit();
				return;
			}
			$upd = $this->db->getQueryBuilder();
			$upd->update('bc_offering')
				->set('eol_state', $upd->createNamedParameter($next))
				->set('eol_action_at', $upd->createNamedParameter($this->nextActionAt($next), 'datetime'))
				->where($upd->expr()->eq('id', $upd->createNamedParameter($id)));
			$upd->executeStatement();

			if ($next === 'purged') {
				$this->purgePii($id);
			}
			$this->audit->log(null, $id, 'cron', 'eol.' . $next,
				(string)$row['eol_state'], $next);
			$this->db->commit();
		} catch (\Throwable) {
			$this->db->rollBack();
		}
	}

	/**
	 * PII tombstones — buyer/attendee names+emails become hashes; orders,
	 * amounts, invoices, payments, audit rows stay intact.
	 */
	private function purgePii(int $offeringId): void
	{
		$tomb = fn (string $v) => 'gdpr:' . substr(hash('sha256', $v), 0, 16);

		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'buyer_name', 'buyer_email')->from('bc_order')
			->where($qb->expr()->eq('offering_id', $qb->createNamedParameter($offeringId)));
		foreach ($qb->executeQuery()->fetchAll() as $o) {
			$upd = $this->db->getQueryBuilder();
			$upd->update('bc_order')
				->set('buyer_name', $upd->createNamedParameter($tomb((string)$o['buyer_name'])))
				->set('buyer_email', $upd->createNamedParameter($tomb((string)$o['buyer_email'])))
				->set('buyer_company', $upd->createNamedParameter(null))
				->set('vat_id', $upd->createNamedParameter(null))
				->where($upd->expr()->eq('id', $upd->createNamedParameter((int)$o['id'])));
			$upd->executeStatement();

			// attendee PII on tickets of this order
			$q = $this->db->getQueryBuilder();
			$q->select('t.id')->from('bc_ticket', 't')
				->innerJoin('t', 'bc_order_line', 'ol', $q->expr()->eq('ol.id', 't.line_id'))
				->where($q->expr()->eq('ol.order_id', $q->createNamedParameter((int)$o['id'])));
			foreach ($q->executeQuery()->fetchAll(\PDO::FETCH_COLUMN) as $tid) {
				$u = $this->db->getQueryBuilder();
				$u->update('bc_ticket')
					->set('attendee_name', $u->createNamedParameter($tomb('a' . $tid)))
					->set('attendee_email', $u->createNamedParameter(null))
					->where($u->expr()->eq('id', $u->createNamedParameter((int)$tid)));
				$u->executeStatement();
			}
		}
		// portal sessions + material links for the offering go too
		$qb = $this->db->getQueryBuilder();
		$qb->delete('bc_upload')
			->where($qb->expr()->eq('offering_id', $qb->createNamedParameter($offeringId)));
		$qb->executeStatement();
	}

	private function nextActionAt(string $state): ?\DateTimeImmutable
	{
		// closing→closed fast (sales stop); closed→warned 30d; warned→purged 60d
		return match ($state) {
			'closed' => new \DateTimeImmutable('+30 days'),
			'warned' => new \DateTimeImmutable('+60 days'),
			default => null,
		};
	}
}

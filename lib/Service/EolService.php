<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\Db\OfferingMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
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
		private readonly \OCP\Files\IAppData $appData,
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
		$qb->select('id', 'ref', 'buyer_name', 'buyer_email')->from('bc_order')
			->where($qb->expr()->eq('offering_id', $qb->createNamedParameter($offeringId)));
		$orders = $qb->executeQuery()->fetchAll();
		$emails = array_values(array_unique(array_map(
			fn ($o) => strtolower((string)$o['buyer_email']), $orders)));

		// queued + dead queue mail still carries the real address — kill it
		// BEFORE the tombstone lands (to_email keys are plaintext). Portal
		// sessions AND otp codes are email-keyed — killing only the session
		// leaves the portal re-entry path alive.
		if ($emails !== []) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('bc_email')->where($qb->expr()->in('to_email',
				$qb->createNamedParameter($emails, IQueryBuilder::PARAM_STR_ARRAY)))
				->executeStatement();
			$qb = $this->db->getQueryBuilder();
			$qb->delete('bc_portal_sess')->where($qb->expr()->in('email',
				$qb->createNamedParameter($emails, IQueryBuilder::PARAM_STR_ARRAY)))
				->executeStatement();
			$qb = $this->db->getQueryBuilder();
			$qb->delete('bc_otp')->where($qb->expr()->in('email',
				$qb->createNamedParameter($emails, IQueryBuilder::PARAM_STR_ARRAY)))
				->executeStatement();
		}

		// queued CRM sync payloads carry buyer name/email/company —
		// delivering them after the tombstone would leak the purged PII.
		// The task payload stores the plain order ref (the 'bck-' prefix
		// is only applied at delivery) — match on the ref itself.
		foreach ($orders as $o) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('bc_sync_task')
				->where($qb->expr()->eq('kind', $qb->createNamedParameter('crm_buyer')))
				->andWhere($qb->expr()->like('payload_json',
					$qb->createNamedParameter('%' . (string)$o['ref'] . '%')))
				->executeStatement();
		}

		foreach ($orders as $o) {
			$upd = $this->db->getQueryBuilder();
			$upd->update('bc_order')
				->set('buyer_name', $upd->createNamedParameter($tomb((string)$o['buyer_name'])))
				->set('buyer_email', $upd->createNamedParameter($tomb((string)$o['buyer_email'])))
				->set('buyer_company', $upd->createNamedParameter(null))
				->set('vat_id', $upd->createNamedParameter(null))
				->where($upd->expr()->eq('id', $upd->createNamedParameter((int)$o['id'])));
			$upd->executeStatement();

			// attendee PII on tickets + their intake answers
			$q = $this->db->getQueryBuilder();
			$q->select('t.id')->from('bc_ticket', 't')
				->innerJoin('t', 'bc_order_line', 'ol', $q->expr()->eq('ol.id', 't.line_id'))
				->where($q->expr()->eq('ol.order_id', $q->createNamedParameter((int)$o['id'])));
			$ticketIds = $q->executeQuery()->fetchAll(\PDO::FETCH_COLUMN);
			if ($ticketIds !== []) {
				$d = $this->db->getQueryBuilder();
				$d->delete('bc_answer')->where($d->expr()->in('ticket_id',
					$d->createNamedParameter($ticketIds, IQueryBuilder::PARAM_INT_ARRAY)))
					->executeStatement();
			}
			foreach ($ticketIds as $tid) {
				$u = $this->db->getQueryBuilder();
				$u->update('bc_ticket')
					->set('attendee_name', $u->createNamedParameter($tomb('a' . $tid)))
					->set('attendee_email', $u->createNamedParameter(null))
					->where($u->expr()->eq('id', $u->createNamedParameter((int)$tid)));
				$u->executeStatement();
			}
			// reviews are buyer-authored text tied to this order's lines
			$d = $this->db->getQueryBuilder();
			$d->delete('bc_review')->where($d->expr()->in('line_id',
				$d->createFunction('(SELECT id FROM *PREFIX*bc_order_line WHERE order_id = '
					. (int)$o['id'] . ')')))->executeStatement();
		}

		// passes carry holder PII — tombstone like the order's buyer fields
		// (columns are NOT NULL — a hash marker, not NULL)
		$u = $this->db->getQueryBuilder();
		$u->update('bc_pass')
			->set('holder_name', $u->createNamedParameter($tomb('pass')))
			->set('holder_email', $u->createNamedParameter($tomb('pass')))
			->where($u->expr()->eq('offering_id', $u->createNamedParameter($offeringId)))
			->executeStatement();

		// waitlist rows hold plaintext emails for this offering's targets
		foreach (['event' => 'bc_event', 'series' => 'bc_series', 'slot' => 'bc_slot'] as $tt => $ttbl) {
			$d = $this->db->getQueryBuilder();
			$d->delete('bc_waitlist')
				->where($d->expr()->eq('target_type', $d->createNamedParameter($tt)))
				->andWhere($d->expr()->in('target_id', $d->createFunction(
					'(SELECT id FROM *PREFIX*' . $ttbl . ' WHERE offering_id = '
					. $offeringId . ')')))->executeStatement();
		}

		// uploads: the BLOB is the PII — purge appdata before the row.
		// abuse reports hold the reporter's plaintext email and FK onto the
		// upload — they must go first or they outlive the purge orphaned.
		$d = $this->db->getQueryBuilder();
		$d->delete('bc_abuse')->where($d->expr()->in('upload_id',
			$d->createFunction('(SELECT id FROM *PREFIX*bc_upload WHERE offering_id = '
				. $offeringId . ')')))->executeStatement();
		$qb = $this->db->getQueryBuilder();
		$qb->select('file_ref')->from('bc_upload')
			->where($qb->expr()->eq('offering_id', $qb->createNamedParameter($offeringId)));
		$refs = $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN);
		if ($refs !== []) {
			try {
				$dir = $this->appData->getFolder('uploads');
				foreach ($refs as $ref) {
					if ($dir->fileExists((string)$ref)) {
						$dir->getFile((string)$ref)->delete();
					}
				}
			} catch (\Throwable) {
				// blob purge is best-effort inside the txn; orphan blobs are
				// worthless without their row and get janitor-cleaned
			}
			$qb = $this->db->getQueryBuilder();
			$qb->delete('bc_upload')
				->where($qb->expr()->eq('offering_id', $qb->createNamedParameter($offeringId)));
			$qb->executeStatement();
		}
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

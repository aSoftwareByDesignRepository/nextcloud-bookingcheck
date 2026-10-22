<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\Db\AuditEntry;
use OCA\BookingCheck\Db\AuditEntryMapper;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Append-only, hash-chained audit log.
 *
 *  - row_hash = sha256(prev_hash || canonical(row fields)) — a DB-level
 *    rewrite/delete of history breaks the chain at that row.
 *  - meta diffs carry field NAMES + "[changed]" for PII values — the audit
 *    trail must never become a shadow copy of erased personal data.
 *  - Append inside the same transaction as the state change it records.
 *  - Appends serialize on a named lock; UNIQUE(prev_hash) is the DB-level
 *    backstop for the lock-miss case (one child per node — a forked
 *    chain is indistinguishable from tampering on verify).
 */
class AuditService
{
	/** Field names whose values must never be written into meta diffs. */
	private const PII_FIELDS = [
		'buyer_name', 'buyer_email', 'buyer_company', 'attendee_name',
		'attendee_email', 'vat_id', 'billing_address', 'value', 'answer',
		'uploader_email', 'email', 'ip', 'user_agent', 'code', 'token',
	];

	private const CHAIN_LOCK = 'bookingcheck/audit-chain';

	public function __construct(
		private readonly AuditEntryMapper $mapper,
		private readonly IDBConnection $db,
		private readonly ILockingProvider $locking,
	) {
	}

	/**
	 * @param array<string,mixed> $meta field→value (non-PII) or field→'[changed]'
	 */
	public function log(
		?int $orderId,
		?int $offeringId,
		string $actor,
		string $action,
		?string $fromState = null,
		?string $toState = null,
		array $meta = [],
	): void {
		$meta = $this->sanitizeMeta($meta);
		$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

		// head read + append must serialize — two txns that read the same
		// head write twin children (a fork verifyChain flags as tampering).
		// The named lock makes that window near-impossible; when contention
		// outlasts the retry budget we proceed anyway — UNIQUE(prev_hash)
		// turns a residual race into a transaction abort, never a fork.
		$locked = $this->acquireChainLock();
		try {
			$prev = $this->chainHead();
			$e = new AuditEntry();
			$e->setOrderId($orderId);
			$e->setOfferingId($offeringId);
			$e->setActor(mb_substr($actor, 0, 64));
			$e->setAction(mb_substr($action, 0, 48));
			$e->setFromState($fromState);
			$e->setToState($toState);
			$e->setMeta($meta === [] ? null : (json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null));
			$e->setPrevHash($prev);
			$e->setCreatedAt($now);
			$e->setRowHash($this->rowHash($prev, $orderId, $offeringId, $actor, $action, $fromState, $toState, $meta, $now));

			$this->mapper->insert($e);
		} finally {
			if ($locked) {
				$this->locking->releaseLock(self::CHAIN_LOCK, ILockingProvider::LOCK_EXCLUSIVE);
			}
		}
	}

	/** Verify the whole chain; returns first broken row id or 0 = intact. */
	/** Rate-cap helper: rows for (action, actor) written in the last $seconds. */
	public function countRecentActions(string $action, string $actor, int $seconds): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))->from('bc_audit')
			->where($qb->expr()->eq('action', $qb->createNamedParameter($action)))
			->andWhere($qb->expr()->eq('actor', $qb->createNamedParameter($actor)))
			->andWhere($qb->expr()->gte('created_at',
				$qb->createNamedParameter(new \DateTimeImmutable('-' . $seconds . ' seconds'), 'datetime_immutable')));
		return (int)$qb->executeQuery()->fetchOne();
	}

	public function verifyChain(): int
	{
		$prev = '0';
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('bc_audit')->orderBy('id', 'ASC');
		$stmt = $qb->executeQuery();
		while (($row = $stmt->fetch()) !== false) {
			$meta = $row['meta'] !== null ? json_decode((string)$row['meta'], true) : [];
			$created = new \DateTimeImmutable((string)$row['created_at'], new \DateTimeZone('UTC'));
			$expect = $this->rowHash(
				$prev,
				$row['order_id'] !== null ? (int)$row['order_id'] : null,
				$row['offering_id'] !== null ? (int)$row['offering_id'] : null,
				(string)$row['actor'],
				(string)$row['action'],
				$row['from_state'] !== null ? (string)$row['from_state'] : null,
				$row['to_state'] !== null ? (string)$row['to_state'] : null,
				is_array($meta) ? $meta : [],
				$created,
			);
			if (!hash_equals($expect, (string)$row['row_hash']) || (string)$row['prev_hash'] !== $prev) {
				return (int)$row['id'];
			}
			$prev = (string)$row['row_hash'];
		}
		return 0;
	}

	/** Replace PII field values with a change marker; keep non-PII values. */
	/** @param array<string,mixed> $meta @return array<string,mixed> */
	private function sanitizeMeta(array $meta): array
	{
		$out = [];
		foreach ($meta as $k => $v) {
			$out[$k] = in_array(strtolower((string)$k), self::PII_FIELDS, true) ? '[changed]' : $v;
		}
		return $out;
	}

	/**
	 * Fresh head, every call — never cached. Under UNIQUE(prev_hash) a
	 * cached head that another writer has already consumed turns the next
	 * append into a constraint violation that aborts a legitimate txn.
	 */
	private function chainHead(): string
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('row_hash')->from('bc_audit')->orderBy('id', 'DESC')->setMaxResults(1);
		$hash = $qb->executeQuery()->fetchOne();
		return $hash === false ? '0' : (string)$hash;
	}

	/** Brief retry — the lock is held for microseconds (head read + insert). */
	private function acquireChainLock(): bool
	{
		for ($i = 0; $i < 6; $i++) {
			try {
				$this->locking->acquireLock(self::CHAIN_LOCK, ILockingProvider::LOCK_EXCLUSIVE);
				return true;
			} catch (LockedException) {
				usleep(15000);
			}
		}
		return false;
	}

	/** @param array<string,mixed> $meta */
	private function rowHash(
		string $prev, ?int $orderId, ?int $offeringId, string $actor, string $action,
		?string $from, ?string $to, array $meta, \DateTimeInterface $at,
	): string {
		$payload = implode('|', [
			$prev,
			$orderId === null ? '' : (string)$orderId,
			$offeringId === null ? '' : (string)$offeringId,
			$actor,
			$action,
			$from ?? '',
			$to ?? '',
			json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
			$at->format('Y-m-d H:i:s'),
		]);
		return hash('sha256', $payload);
	}
}

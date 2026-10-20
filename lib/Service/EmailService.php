<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\Db\EmailOutbox;
use OCA\BookingCheck\Db\EmailOutboxMapper;
use OCP\IDBConnection;

/**
 * Transactional email queue — never sends inside a request/transaction.
 * Rows land in bc_email inside the same txn as the state change that
 * triggered them; EmailQueueJob delivers + retries.
 */
class EmailService
{
	public const PER_EMAIL_HOURLY_CAP = 5;

	public function __construct(
		private readonly EmailOutboxMapper $outbox,
		private readonly IDBConnection $db,
	) {
	}

	/**
	 * Queue an email. Per-email send caps apply to abuse-prone kinds
	 * (OTP, waitlist notify, magic links) — counted over the last hour
	 * across pending+sent, so a rotating-IP bomber cannot mailbomb.
	 */
	public function queue(string $kind, string $to, string $locale, array $payload, bool $capped = false): bool
	{
		$to = strtolower(trim($to));
		if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
			return false;
		}
		if ($capped && $this->recentSendCount($to) >= self::PER_EMAIL_HOURLY_CAP) {
			return false; // silently dropped — the public response is uniform anyway
		}
		$e = new EmailOutbox();
		$e->setKind($kind);
		$e->setToEmail($to);
		$e->setLocale($locale);
		$e->setPayloadJson(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
		$e->setCreatedAt(new \DateTimeImmutable());
		$this->outbox->insert($e);
		return true;
	}

	private function recentSendCount(string $email): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('bc_email')
			->where($qb->expr()->eq('to_email', $qb->createNamedParameter($email)))
			->andWhere($qb->expr()->gte('created_at',
				$qb->createNamedParameter(new \DateTime('-1 hour'), 'datetime')));
		return (int)$qb->executeQuery()->fetchOne();
	}
}

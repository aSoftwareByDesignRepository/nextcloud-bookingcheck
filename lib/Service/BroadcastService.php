<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\Db\Broadcast;
use OCA\BookingCheck\Db\BroadcastMapper;
use OCA\BookingCheck\Exception\ValidationException;
use OCP\IDBConnection;

/**
 * Attendee broadcasts — one seller-authored message fanned out to paid
 * buyers of an offering (optionally scoped to one occurrence). Emails
 * are queued, never sent inline; the broadcast row is the audit record.
 */
class BroadcastService
{
	private const MAX_RECIPIENTS = 2000;

	public function __construct(
		private readonly IDBConnection $db,
		private readonly BroadcastMapper $broadcasts,
		private readonly EmailService $email,
		private readonly LicenseService $license,
	) {
	}

	/**
	 * @param array{0:string,1:int}|null $occurrence [target_type,target_id] filter
	 * @return array{broadcastId:int,recipients:int}
	 */
	public function send(int $offeringId, ?array $occurrence, string $subject, string $bodyMd, string $sentBy): array
	{
		$this->license->requireFeature('broadcasts');
		$subject = trim($subject);
		$bodyMd = trim($bodyMd);
		$fields = [];
		if (mb_strlen($subject) < 3 || mb_strlen($subject) > 190) {
			$fields['subject'] = 'Subject must be 3–190 characters.';
		}
		if ($bodyMd === '' || mb_strlen($bodyMd) > 8000) {
			$fields['body'] = 'Message must be 1–8000 characters.';
		}
		if ($occurrence !== null && !in_array($occurrence[0] ?? '', ['event', 'slot', 'series'], true)) {
			$fields['occurrence'] = 'Unknown occurrence type.';
		}
		if ($fields !== []) {
			throw new ValidationException($fields);
		}

		// distinct paid buyers — dedupe on email, keep first locale seen
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('o.buyer_email')->addSelect('o.buyer_locale')
			->from('bc_order', 'o')
			->where($qb->expr()->eq('o.offering_id', $qb->createNamedParameter($offeringId)))
			->andWhere($qb->expr()->eq('o.status', $qb->createNamedParameter('paid')))
			->setMaxResults(self::MAX_RECIPIENTS);
		if ($occurrence !== null) {
			$qb->innerJoin('o', 'bc_order_line', 'l', $qb->expr()->andX(
				$qb->expr()->eq('l.order_id', 'o.id'),
				$qb->expr()->eq('l.line_kind', $qb->createNamedParameter('seat')),
				$qb->expr()->eq('l.target_type', $qb->createNamedParameter($occurrence[0])),
				$qb->expr()->eq('l.target_id', $qb->createNamedParameter($occurrence[1]))
			));
		}
		$rows = $qb->executeQuery()->fetchAll();

		$sent = 0;
		$seen = [];
		foreach ($rows as $r) {
			$to = strtolower((string)$r['buyer_email']);
			if ($to === '' || isset($seen[$to])) {
				continue;
			}
			$seen[$to] = true;
			if ($this->email->queue('broadcast', $to, (string)$r['buyer_locale'],
				['subject' => $subject, 'body' => $bodyMd])) {
				$sent++;
			}
		}

		$b = new Broadcast();
		$b->setOfferingId($offeringId);
		$b->setScope($occurrence === null ? 'all' : $occurrence[0] . ':' . $occurrence[1]);
		$b->setSubject($subject);
		$b->setBodyMd($bodyMd);
		$b->setSentBy($sentBy);
		$b->setSentAt(new \DateTimeImmutable());
		$b->setRcptCount($sent);
		$this->broadcasts->insert($b);

		return ['broadcastId' => (int)$b->getId(), 'recipients' => $sent];
	}
}

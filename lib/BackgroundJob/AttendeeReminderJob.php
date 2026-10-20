<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\BackgroundJob;

use OCA\BookingCheck\Db\EventMapper;
use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Db\OrderLineMapper;
use OCA\BookingCheck\Db\OrderMapper;
use OCA\BookingCheck\Db\ReminderMapper;
use OCA\BookingCheck\Db\SlotMapper;
use OCA\BookingCheck\Service\EmailService;
use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;

/**
 * NH-20 — attendee reminder email N days before the booked start.
 *
 * Per-offering `reminder_days` (0 = off). Dedupe is a UNIQUE row on
 * (order_id, target_type, target_id) claimed BEFORE queueing — two
 * overlapping cron runs never double-send; a rescheduled booking uses
 * a different target row and correctly re-reminds.
 */
class AttendeeReminderJob extends TimedJob
{
	private const BATCH = 500;

	public function __construct(
		ITimeFactory $time,
		private readonly OfferingMapper $offerings,
		private readonly EventMapper $events,
		private readonly SlotMapper $slots,
		private readonly OrderLineMapper $lines,
		private readonly OrderMapper $orders,
		private readonly ReminderMapper $reminders,
		private readonly EmailService $email,
		private readonly IURLGenerator $urls,
		private readonly \OCP\IDBConnection $db,
	) {
		parent::__construct($time);
		$this->setInterval(3600); // hourly — day-granularity needs nothing faster
	}

	protected function run($argument): void
	{
		// offerings with reminders enabled
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'reminder_days')
			->from('bc_offering')
			->where($qb->expr()->gt('reminder_days', $qb->createNamedParameter(0)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('published')));
		$due = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$due[(int)$row['id']] = (int)$row['reminder_days'];
		}
		if ($due === []) {
			return;
		}

		foreach ($due as $offeringId => $days) {
			$this->processOffering($offeringId, $days);
		}
	}

	private function processOffering(int $offeringId, int $days): void
	{
		// window: starts_at within (now+days-ε, now+days+1d) — the daily
		// cron granularity guarantees each target is inside exactly once
		$from = (new \DateTimeImmutable())->modify('+' . $days . ' days');
		$to = $from->modify('+1 day');
		foreach (['event', 'slot'] as $type) {
			$targets = $type === 'event'
				? $this->targetsInWindow('bc_event', $offeringId, $from, $to)
				: $this->targetsInWindow('bc_slot', $offeringId, $from, $to);
			foreach ($targets as $target) {
				$this->remindTarget($type, $target);
			}
		}
	}

	/** @return list<array{id:int,starts_at:mixed}> */
	private function targetsInWindow(string $table, int $offeringId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('t.id', 't.starts_at')
			->from($table, 't')
			->innerJoin('t', 'bc_offering', 'o', $qb->expr()->eq('o.id', 't.offering_id'))
			->where($qb->expr()->eq('o.id', $qb->createNamedParameter($offeringId)))
			->andWhere($qb->expr()->gte('t.starts_at', $qb->createNamedParameter($from, 'datetime_immutable')))
			->andWhere($qb->expr()->lt('t.starts_at', $qb->createNamedParameter($to, 'datetime_immutable')))
			->setMaxResults(self::BATCH);
		if ($table === 'bc_event') {
			$qb->andWhere($qb->expr()->eq('t.status', $qb->createNamedParameter('scheduled')));
		}
		/** @var list<array{id:int,starts_at:mixed}> $rows */
		$rows = $qb->executeQuery()->fetchAll();
		return $rows;
	}

	/** @param array{id:int,starts_at:mixed} $target */
	private function remindTarget(string $type, array $target): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('o.id AS order_id', 'o.buyer_email', 'o.buyer_name', 'o.buyer_locale',
			'o.ref', 'of.title AS offering_title')
			->from('bc_order_line', 'l')
			->innerJoin('l', 'bc_order', 'o', $qb->expr()->eq('o.id', 'l.order_id'))
			->innerJoin('l', 'bc_offering', 'of', $qb->expr()->eq('of.id', 'o.offering_id'))
			->where($qb->expr()->eq('l.target_type', $qb->createNamedParameter($type)))
			->andWhere($qb->expr()->eq('l.target_id', $qb->createNamedParameter((int)$target['id'])))
			->andWhere($qb->expr()->eq('o.status', $qb->createNamedParameter('paid')))
			->groupBy('o.id');
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$orderId = (int)$row['order_id'];
			if (!$this->reminders->claim($orderId, $type, (int)$target['id'])) {
				continue; // already sent by an earlier run
			}
			$order = $this->orders->find($orderId);
			if ($order === null) {
				continue;
			}
			// reminders never carry tokens — the manage link lives in the
			// confirmation mail; a reminder re-shipping it widens theft scope
			$starts = $target['starts_at'] instanceof \DateTimeInterface
				? $target['starts_at']->format('Y-m-d H:i')
				: (string)$target['starts_at'];
			try {
				$queued = $this->email->queue('reminder', (string)$row['buyer_email'],
					(string)$row['buyer_locale'], [
						'order_ref' => (string)$row['ref'],
						'title' => (string)$row['offering_title'],
						'starts_at' => $starts,
					]);
			} catch (\Throwable) {
				$queued = false;
			}
			if (!$queued) {
				// release the dedupe claim — at-least-once delivery beats
				// silently losing the reminder to a transient queue failure
				$this->reminders->release($orderId, $type, (int)$target['id']);
			}
		}
	}
}

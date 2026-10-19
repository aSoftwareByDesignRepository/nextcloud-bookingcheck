<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\Db\Slot;
use OCA\BookingCheck\Db\SlotMapper;
use OCA\BookingCheck\Db\SlotTpl;
use OCA\BookingCheck\Db\SlotTplMapper;
use OCP\IDBConnection;

/**
 * Timeslot materializer — turns a SlotTpl's weekly schedule into concrete
 * bc_slot rows inside the rolling horizon window.
 *
 * `weekly_rrule` format (one entry per line, blank lines ignored):
 *   MO 10:00
 *   WE 14:30
 *   FR 09:00,11:00        — multiple start times per weekday allowed
 *
 * Window: from now + lead_min up to now + horizon_days. Idempotent by
 * (tpl_id, starts_at) — re-runs only add newly-in-range occurrences, so
 * the daily job can keep the horizon rolling safely.
 */
class SlotMaterializer
{
	private const DOW = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];
	private const MAX_PER_RUN = 400;

	public function __construct(
		private readonly IDBConnection $db,
		private readonly SlotMapper $slots,
		private readonly SlotTplMapper $tpls,
		private readonly ?\OCA\BookingCheck\Db\SlotResourceMapper $slotRes = null,
		private readonly ?\OCA\BookingCheck\Db\ResourceMapper $resources = null,
	) {
	}

	/** @return list<array{0:int,1:string}> [weekday 1-7, "HH:MM"] */
	public static function parseRrule(?string $raw): array
	{
		$out = [];
		foreach (preg_split('/[\r\n]+/', (string)$raw) ?: [] as $line) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}
			if (!preg_match('/^(MO|TU|WE|TH|FR|SA|SU)\s+(.+)$/i', $line, $m)) {
				throw new \InvalidArgumentException('Each line must look like "MO 10:00".');
			}
			$dow = self::DOW[strtoupper($m[1])];
			foreach (explode(',', $m[2]) as $time) {
				$time = trim($time);
				if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $t)) {
					throw new \InvalidArgumentException('Times must be HH:MM — got "' . $time . '".');
				}
				$out[] = [$dow, sprintf('%02d:%02d', (int)$t[1], (int)$t[2])];
			}
		}
		if ($out === []) {
			throw new \InvalidArgumentException('Add at least one weekly time, e.g. "MO 10:00".');
		}
		return $out;
	}

	/**
	 * Materialize one template's window. Returns the number of NEW slots
	 * created — existing (tpl_id, starts_at) pairs are skipped.
	 */
	public function materialize(SlotTpl $tpl): int
	{
		$entries = self::parseRrule($tpl->getWeeklyRrule());
		// starts_at is server-local everywhere in this app (IcsService
		// converts to UTC on the wire) — materialize in the same frame.
		$now = new \DateTimeImmutable();
		$from = $now->modify('+' . max(0, (int)$tpl->getLeadMin()) . ' minutes');
		$until = $now->modify('+' . max(1, (int)$tpl->getHorizonDays()) . ' days');
		$duration = max(1, (int)$tpl->getDurationMin());

		$existing = $this->existingStarts((int)$tpl->getId(), $from, $until);
		$created = 0;

		// iterate day by day inside the window — bounded by horizon_days
		for ($day = $from->setTime(0, 0); $day <= $until && $created < self::MAX_PER_RUN; $day = $day->modify('+1 day')) {
			$dow = (int)$day->format('N');
			foreach ($entries as [$ed, $hhmm]) {
				if ($ed !== $dow) {
					continue;
				}
				[$h, $m] = array_map('intval', explode(':', $hhmm));
				$start = $day->setTime($h, $m);
				if ($start < $from || $start > $until) {
					continue;
				}
				$key = $start->format('Y-m-d H:i:s');
				if (isset($existing[$key])) {
					continue; // idempotent — already materialized
				}
				if ($created >= self::MAX_PER_RUN) {
					break 2;
				}
				$s = new Slot();
				$s->setTplId((int)$tpl->getId());
				$s->setOfferingId((int)$tpl->getOfferingId());
				$s->setStartsAt($start);
				$s->setEndsAt($start->modify('+' . $duration . ' minutes'));
				$s->setCapacity(max(1, (int)$tpl->getCapPerSlot()));
				$this->slots->insert($s);
				// resource-bound templates sell the resource's pool per slot —
				// the link row is what holdSlot()'s counter lives on
				if ($tpl->getResourceId() !== null && $this->slotRes !== null) {
					$res = $this->resources?->find((int)$tpl->getResourceId());
					if ($res !== null && (int)$res->getActive() === 1
						&& (int)$res->getOfferingId() === (int)$tpl->getOfferingId()) {
						$sr = new \OCA\BookingCheck\Db\SlotResource();
						$sr->setSlotId((int)$s->getId());
						$sr->setResourceId((int)$res->getId());
						$sr->setCapacity(max(1, (int)$res->getCapacity()));
						$this->slotRes->insert($sr);
					}
				}
				$existing[$key] = true;
				$created++;
			}
		}
		return $created;
	}

	/** Materialize every template — the daily job's sweep. */
	public function materializeAll(): int
	{
		$created = 0;
		foreach ($this->tpls->findAll() as $tpl) {
			$created += $this->materialize($tpl);
		}
		return $created;
	}

	/** @return array<string,true> set of "Y-m-d H:i:s" starts already present */
	private function existingStarts(int $tplId, \DateTimeImmutable $from, \DateTimeImmutable $until): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('starts_at')->from('bc_slot')
			->where($qb->expr()->eq('tpl_id', $qb->createNamedParameter($tplId)))
			->andWhere($qb->expr()->gte('starts_at', $qb->createNamedParameter($from->setTime(0, 0), 'datetime')))
			->andWhere($qb->expr()->lte('starts_at', $qb->createNamedParameter($until, 'datetime')));
		$set = [];
		foreach ($qb->executeQuery()->fetchAll() as $r) {
			$set[(string)$r['starts_at']] = true;
		}
		return $set;
	}
}

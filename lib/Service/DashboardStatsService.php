<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\Middleware\AppAccessMiddleware;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;

/**
 * US-523 widget reads — derived from live rows only (AC-2: honest zero
 * state, no cached aggregates). Scoped to the seller role: users without
 * it get an empty result set, never partial data (AC-3).
 */
class DashboardStatsService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly IGroupManager $groups,
	) {
	}

	public function canSee(string $uid): bool
	{
		return $uid !== ''
			&& ($this->groups->isAdmin($uid)
				|| $this->groups->isInGroup($uid, AppAccessMiddleware::ADMIN_GROUP));
	}

	/** Tickets checked in since local midnight today. */
	public function todayCheckins(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('t.id'))->from('bc_ticket', 't')
			->where($qb->expr()->isNotNull('t.checked_in_at'))
			->andWhere($qb->expr()->gte('t.checked_in_at',
				$qb->createNamedParameter((new \DateTimeImmutable('today'))->format('Y-m-d H:i:s'))));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** Paid orders settled in the last 7 days: [count, cents]. */
	public function weekSales(): array
	{
		$qb = $this->db->getQueryBuilder();
		// fetch() is associative in NC — alias the aggregates, numeric
		// indexes silently yield the honest-looking wrong answer 0.
		$qb->selectAlias($qb->func()->count('id'), 'c')
			->selectAlias($qb->func()->sum('amount_cents'), 's')
			->from('bc_order')
			->where($qb->expr()->in('status',
				$qb->createNamedParameter(['paid', 'refunded'], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->eq('is_test', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('paid_at',
				$qb->createNamedParameter((new \DateTimeImmutable('-7 days'))->format('Y-m-d H:i:s'))));
		$row = $qb->executeQuery()->fetch();
		return ['count' => (int)($row['c'] ?? 0), 'cents' => (int)($row['s'] ?? 0)];
	}

	/** Next upcoming bookable occurrences (events + slots), $limit max. */
	public function upcoming(int $limit = 5): array
	{
		$now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
		$out = [];
		foreach (['bc_event', 'bc_slot'] as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('o.title', 'e.starts_at')
				->from($table, 'e')
				->innerJoin('e', 'bc_offering', 'o', $qb->expr()->eq('o.id', 'e.offering_id'))
				->where($qb->expr()->gte('e.starts_at', $qb->createNamedParameter($now)))
				->orderBy('e.starts_at', 'ASC')
				->setMaxResults($limit);
			foreach ($qb->executeQuery()->fetchAll() as $r) {
				$out[] = ['title' => (string)$r['title'], 'starts' => (string)$r['starts_at']];
			}
		}
		usort($out, fn ($a, $b) => $a['starts'] <=> $b['starts']);
		return array_slice($out, 0, $limit);
	}
}

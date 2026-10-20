<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Exception\NotFoundException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IDBConnection;
use OCP\IRequest;

/**
 * NH-22 — iCal subscription feeds.
 *
 * Two surfaces:
 *  - seller feed: every upcoming occurrence of published offerings,
 *    token-gated by the `ical_feed_token` setting (rotatable, compared
 *    constant-time — never guessable, empty = feed disabled).
 *    Public-safe payload: title + time only, zero buyer PII.
 *  - order feed: the buyer's own booking, gated by the 256-bit manage
 *    token — same truth source as manage#ics (STATUS:CANCELLED on
 *    refund so calendars clean themselves).
 */
class IcalController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly \OCA\BookingCheck\Service\IcsService $ics,
		private readonly \OCA\BookingCheck\Service\SettingsService $settings,
		private readonly \OCA\BookingCheck\Service\TokenService $tokens,
		private readonly \OCA\BookingCheck\Db\OrderMapper $orders,
		private readonly IDBConnection $db,
	) {
		parent::__construct($appName, $request);
	}

	/** Seller schedule feed — subscription URL for calendar apps. */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function seller(string $token): DataDownloadResponse
	{
		$expected = $this->settings->get('ical_feed_token');
		if ($expected === '' || !hash_equals($expected, $token)) {
			throw new NotFoundException();
		}
		$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$horizon = $now->modify('+1 year');
		$events = [];

		// events for published offerings
		$qb = $this->db->getQueryBuilder();
		$qb->select('e.id', 'e.starts_at', 'e.ends_at', 'e.status', 'o.title')
			->from('bc_event', 'e')
			->innerJoin('e', 'bc_offering', 'o', $qb->expr()->eq('o.id', 'e.offering_id'))
			->where($qb->expr()->eq('o.status', $qb->createNamedParameter('published')))
			->andWhere($qb->expr()->gte('e.ends_at', $qb->createNamedParameter($now->modify('-1 day'), 'datetime_immutable')))
			->andWhere($qb->expr()->lte('e.starts_at', $qb->createNamedParameter($horizon, 'datetime_immutable')))
			->setMaxResults(2000);
		foreach ($qb->executeQuery()->fetchAll() as $r) {
			$events[] = [
				'uid' => 'bck-event-' . $r['id'],
				'title' => (string)$r['title'],
				'start' => new \DateTimeImmutable((string)$r['starts_at'], new \DateTimeZone('UTC')),
				'end' => new \DateTimeImmutable((string)$r['ends_at'], new \DateTimeZone('UTC')),
				'cancelled' => $r['status'] === 'cancelled',
			];
		}
		// materialized slots likewise
		$qb = $this->db->getQueryBuilder();
		$qb->select('s.id', 's.starts_at', 's.ends_at', 'o.title')
			->from('bc_slot', 's')
			->innerJoin('s', 'bc_offering', 'o', $qb->expr()->eq('o.id', 's.offering_id'))
			->where($qb->expr()->eq('o.status', $qb->createNamedParameter('published')))
			->andWhere($qb->expr()->gte('s.ends_at', $qb->createNamedParameter($now->modify('-1 day'), 'datetime_immutable')))
			->andWhere($qb->expr()->lte('s.starts_at', $qb->createNamedParameter($horizon, 'datetime_immutable')))
			->setMaxResults(2000);
		foreach ($qb->executeQuery()->fetchAll() as $r) {
			$events[] = [
				'uid' => 'bck-slot-' . $r['id'],
				'title' => (string)$r['title'],
				'start' => new \DateTimeImmutable((string)$r['starts_at'], new \DateTimeZone('UTC')),
				'end' => new \DateTimeImmutable((string)$r['ends_at'], new \DateTimeZone('UTC')),
				'cancelled' => false, // slots carry no status — removed rows simply vanish
			];
		}
		usort($events, fn ($a, $b) => $a['start'] <=> $b['start']);
		$body = $this->ics->forFeed(array_slice($events, 0, 2000),
			$this->settings->get('seller_name') !== '' ? $this->settings->get('seller_name') : 'BookingCheck');
		return new DataDownloadResponse($body, 'bookingcheck-schedule.ics', 'text/calendar');
	}

	/** Buyer order feed — manage-token gated, single order. */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function order(string $token): DataDownloadResponse
	{
		$order = $this->orders->findByManageTokenHash($this->tokens->hash($token));
		if ($order === null) {
			throw new NotFoundException();
		}
		$body = $this->ics->forOrder($order);
		return new DataDownloadResponse($body, 'booking-' . $order->getRef() . '.ics', 'text/calendar');
	}
}

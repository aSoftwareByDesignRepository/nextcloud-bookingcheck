<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\CompanionToken;
use OCA\BookingCheck\Service\TicketService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use OCP\IRequest;

/**
 * Versioned companion API (v1) — bookingcheck-scan / bookingcheck-manager.
 * Auth via CompanionAuthMiddleware (Bearer device token); $companionDevice
 * is injected by the middleware. Door-role devices get check-in only;
 * manager adds undo + stats. `offering_ids` (JSON null = all) scopes
 * every data access — a door tablet at seller A never sees seller B.
 */
class CapiController extends Controller
{
	use ApiControllerTrait;

	/** @var CompanionToken|null injected by middleware */
	public ?\OCA\BookingCheck\Db\CompanionToken $companionDevice = null;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly TicketService $tickets,
		private readonly IDBConnection $db,
		private readonly ?\OCA\BookingCheck\Service\PassService $passes = null,
		private readonly ?\OCA\BookingCheck\Service\OrderService $orderService = null,
		private readonly ?\OCA\BookingCheck\Db\CompanionTokenMapper $companions = null,
		private readonly ?\OCA\BookingCheck\Service\RefundService $refunds = null,
		private readonly ?\OCA\BookingCheck\Db\OrderMapper $orderMapper = null,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function manifest(): JSONResponse
	{
		return $this->ok([
			'api' => 'v1',
			'role' => $this->companionDevice?->getRole() ?? 'door',
			'capabilities' => match ($this->companionDevice?->getRole() ?? 'door') {
				'manager' => ['events', 'roster', 'check-in', 'stats', 'push', 'pass', 'walkup', 'orders', 'refund'],
				'api' => ['events', 'roster', 'check-in', 'stats', 'push', 'pass', 'walkup', 'offerings.write'],
				default => ['events', 'roster', 'check-in', 'push', 'pass', 'walkup'],
			},
		]);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function events(): JSONResponse
	{
		$scope = $this->deviceScope();
		$since = new \DateTime('-1 day');
		$intArr = \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY;
		$events = [];

		// standalone occurrences — series members surface through their
		// series row below, so the door sees ONE scannable target per
		// series instead of the same admission listed N times.
		$qb = $this->db->getQueryBuilder();
		$qb->select('e.id', 'e.starts_at', 'e.ends_at', 'e.capacity', 'e.sold',
			'o.title', 'o.id AS offering_id')
			->from('bc_event', 'e')
			->innerJoin('e', 'bc_offering', 'o', $qb->expr()->eq('o.id', 'e.offering_id'))
			->where($qb->expr()->eq('e.status', $qb->createNamedParameter('scheduled')))
			->andWhere($qb->expr()->isNull('e.series_id'))
			->andWhere($qb->expr()->gte('e.starts_at',
				$qb->createNamedParameter($since, 'datetime')))
			->orderBy('e.starts_at', 'ASC')
			->setMaxResults(200);
		if ($scope !== null) {
			$qb->andWhere($qb->expr()->in('o.id',
				$qb->createNamedParameter($scope, $intArr)));
		}
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$row['type'] = 'event';
			$events[] = $row;
		}

		// timeslots — identical door semantics (capacity + window)
		$qb = $this->db->getQueryBuilder();
		$qb->select('s.id', 's.starts_at', 's.ends_at', 's.capacity', 's.sold',
			'o.title', 'o.id AS offering_id')
			->from('bc_slot', 's')
			->innerJoin('s', 'bc_offering', 'o', $qb->expr()->eq('o.id', 's.offering_id'))
			->where($qb->expr()->gte('s.starts_at',
				$qb->createNamedParameter($since, 'datetime')))
			->orderBy('s.starts_at', 'ASC')
			->setMaxResults(200);
		if ($scope !== null) {
			$qb->andWhere($qb->expr()->in('o.id',
				$qb->createNamedParameter($scope, $intArr)));
		}
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$row['type'] = 'slot';
			$events[] = $row;
		}

		// series — only when a member occurrence is still upcoming; the
		// earliest upcoming member supplies the displayed window. Both
		// subqueries read one consistent snapshot.
		$qb = $this->db->getQueryBuilder();
		$member = fn (string $col): string => '(SELECT e2.' . $col
			. ' FROM *PREFIX*bc_event e2 WHERE e2.series_id = s.id'
			. " AND e2.status = 'scheduled' AND e2.starts_at >= "
			. $qb->createNamedParameter($since, 'datetime')
			. ' ORDER BY e2.starts_at ASC LIMIT 1)';
		$qb->select('s.id', 's.offering_id', 's.title', 's.capacity', 's.sold')
			->selectAlias($qb->createFunction($member('starts_at')), 'starts_at')
			->selectAlias($qb->createFunction($member('ends_at')), 'ends_at')
			->from('bc_series', 's')
			->where($qb->expr()->isNotNull($qb->createFunction($member('id'))))
			->setMaxResults(200);
		if ($scope !== null) {
			$qb->andWhere($qb->expr()->in('s.offering_id',
				$qb->createNamedParameter($scope, $intArr)));
		}
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$row['type'] = 'series';
			$events[] = $row;
		}

		usort($events, fn (array $a, array $b): int =>
			strnatcmp((string)$a['starts_at'], (string)$b['starts_at']));
		return $this->ok(['events' => array_slice($events, 0, 200)]);
	}

	/** Roster for a bookable target — the route predates target_type, so the
	 *  id is resolved against events first, then slots (both are listed as
	 *  door targets). Unscoped and foreign devices get the same 404. */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function roster(int $id): JSONResponse
	{
		[$targetType] = $this->resolveTarget($id) ?? [null];
		$resolved = $targetType === null ? null : $this->resolveDoorTarget($targetType, $id);
		if ($resolved === null || !$this->scopeAllows($resolved[2])) {
			return $this->notFound();
		}
		return $this->rosterForTarget($resolved[0], $resolved[1]);
	}

	/** Typed roster — preferred contract. Event and slot ids share a numeric
	 *  space; the legacy /slots/{id}/roster route resolves event-first and
	 *  cannot reach a colliding slot id. */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function targetRoster(string $type, int $id): JSONResponse
	{
		$resolved = in_array($type, ['event', 'slot', 'series'], true)
			? $this->resolveDoorTarget($type, $id) : null;
		if ($resolved === null || !$this->scopeAllows($resolved[2])) {
			return $this->notFound();
		}
		return $this->rosterForTarget($resolved[0], $resolved[1]);
	}

	private function rosterForTarget(string $targetType, int $id): JSONResponse
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('t.id', 't.attendee_name', 't.seat_no', 't.checked_in_at')
			->from('bc_ticket', 't')
			->innerJoin('t', 'bc_order_line', 'ol', $qb->expr()->eq('ol.id', 't.line_id'))
			->innerJoin('ol', 'bc_order', 'o', $qb->expr()->eq('o.id', 'ol.order_id'))
			->where($qb->expr()->eq('ol.target_id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('ol.target_type', $qb->createNamedParameter($targetType)))
			->andWhere($qb->expr()->eq('o.status', $qb->createNamedParameter('paid')))
			->andWhere($qb->expr()->isNull('t.revoked_at'))
			->orderBy('t.attendee_name', 'ASC');
		$roster = $qb->executeQuery()->fetchAll();
		// door-visible intake (dietary/mobility flags the door needs to know)
		$ids = array_map(fn ($r) => (int)$r['id'], $roster);
		if ($ids !== []) {
			$aq = $this->db->getQueryBuilder();
			$aq->select('a.ticket_id', 'a.value', 'q.label')
				->from('bc_answer', 'a')
				->innerJoin('a', 'bc_question', 'q', $aq->expr()->eq('q.id', 'a.question_id'))
				->where($aq->expr()->in('a.ticket_id',
					$aq->createNamedParameter($ids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($aq->expr()->eq('q.visible_to', $aq->createNamedParameter('door')));
			$byTicket = [];
			foreach ($aq->executeQuery()->fetchAll() as $r) {
				$byTicket[(int)$r['ticket_id']][] = ['label' => (string)$r['label'], 'value' => (string)($r['value'] ?? '')];
			}
			foreach ($roster as &$r) {
				$r['answers'] = $byTicket[(int)$r['id']] ?? [];
			}
			unset($r);
		}
		return $this->ok(['roster' => $roster]);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function checkIn(): JSONResponse
	{
		$qr = (string)$this->request->getParam('qr', '');
		if (str_starts_with($qr, 'BCK1P-')) {
			return $this->admitPassPayload($qr);
		}
		// BCK1:<ticketId>:<token>
		if (!preg_match('/^BCK1:\d+:([A-Za-z0-9_-]{32,64})$/', $qr, $m)) {
			return $this->fail(400, 'BAD_QR', 'Not a BookingCheck ticket.');
		}
		// resolve BEFORE marking — the offering scope check must not mutate
		$ticket = $this->tickets->resolveQrToken($m[1]);
		if ($ticket === null) {
			return $this->notFound();
		}
		$offeringId = $this->tickets->offeringIdForTicket($ticket);
		if ($offeringId === null || !$this->scopeAllows($offeringId)) {
			return $this->notFound(); // foreign-scope ticket — uniform answer
		}
		$result = $this->tickets->checkInByToken($m[1],
			'device:' . ($this->companionDevice?->getLabel() ?? 'unknown'));
		if (!$result['ok']) {
			if ($result['revoked'] ?? false) {
				return $this->fail(409, 'TICKET_REVOKED', 'This ticket was cancelled or refunded.');
			}
			return $this->notFound();
		}
		return $this->ok([
			'checked_in' => true,
			'already' => $result['already'],
			'attendee' => $result['ticket']->getAttendeeName(),
			'seat' => $result['ticket']->getSeatNo(),
		]);
	}

	/** Un-admit — manager devices only. A door tablet that can undo turns
	 *  a scanned ticket back into a valid bearer (re-admission fraud). */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function undoCheckIn(): JSONResponse
	{
		if ($this->companionDevice?->getRole() !== 'manager') {
			return $this->fail(403, 'FORBIDDEN', 'Manager devices only.');
		}
		$id = $this->strictId($this->request->getParam('ticket_id'));
		if ($id === null) {
			return $this->fail(400, 'VALIDATION', 'ticket_id required.');
		}
		$qb = $this->db->getQueryBuilder();
		$ticket = $qb->select('line_id')->from('bc_ticket')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->executeQuery()->fetch();
		if ($ticket === false) {
			return $this->notFound();
		}
		// scope resolves via the line's TARGET — the order's offering_id
		// is only the cart lead and would mis-scope multi-offering lines
		$qb2 = $this->db->getQueryBuilder();
		$target = $qb2->select('ol.target_type', 'ol.target_id')
			->from('bc_order_line', 'ol')
			->where($qb2->expr()->eq('ol.id', $qb2->createNamedParameter((int)$ticket['line_id'])))
			->executeQuery()->fetch();
		if ($target === false) {
			return $this->notFound();
		}
		$offeringId = $this->offeringIdForTarget(
			(string)$target['target_type'], (int)$target['target_id']);
		if ($offeringId === null || !$this->scopeAllows($offeringId)) {
			return $this->notFound();
		}
		$this->tickets->undoCheckIn($id);
		return $this->ok(['checked_in' => false]);
	}

	/** Manager dashboard — per-offering door stats for upcoming targets. */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function stats(): JSONResponse
	{
		if ($this->companionDevice?->getRole() !== 'manager') {
			return $this->fail(403, 'FORBIDDEN', 'Manager devices only.');
		}
		$scope = $this->deviceScope();
		$since = new \DateTime('-1 day');
		$intArr = \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY;
		$checkedIn = static function (string $type, string $idCol): string {
			return '(SELECT COUNT(*) FROM *PREFIX*bc_ticket t
				  JOIN *PREFIX*bc_order_line ol ON ol.id = t.line_id
				  WHERE ol.target_type = \'' . $type . '\'
				    AND ol.target_id = ' . $idCol . '
				    AND t.checked_in_at IS NOT NULL
				    AND t.revoked_at IS NULL)';
		};
		$rows = [];

		// per-occurrence stats — unlike the door list, series members DO
		// appear individually (managers want per-date numbers)
		foreach ([
			['bc_event', 'e', 'event', "e.status = 'scheduled'"],
			['bc_slot', 's', 'slot', '1 = 1'],
		] as [$table, $alias, $type, $extra]) {
			$qb = $this->db->getQueryBuilder();
			$qb->select($alias . '.id', 'o.title', $alias . '.capacity', $alias . '.sold',
					$alias . '.starts_at')
				->selectAlias($qb->createFunction($checkedIn($type, $alias . '.id')), 'checked_in')
				->from($table, $alias)
				->innerJoin($alias, 'bc_offering', 'o',
					$qb->expr()->eq('o.id', $alias . '.offering_id'))
				->where($qb->expr()->gte($alias . '.starts_at',
					$qb->createNamedParameter($since, 'datetime')))
				->andWhere($extra)
				->orderBy($alias . '.starts_at', 'ASC')
				->setMaxResults(200);
			if ($scope !== null) {
				$qb->andWhere($qb->expr()->in('o.id',
					$qb->createNamedParameter($scope, $intArr)));
			}
			foreach ($qb->executeQuery()->fetchAll() as $r) {
				$r['type'] = $type;
				$rows[] = $r;
			}
		}

		// series totals — only while a member occurrence is upcoming
		$qb = $this->db->getQueryBuilder();
		$member = '(SELECT e2.starts_at FROM *PREFIX*bc_event e2'
			. ' WHERE e2.series_id = s.id AND e2.status = \'scheduled\' AND e2.starts_at >= '
			. $qb->createNamedParameter($since, 'datetime')
			. ' ORDER BY e2.starts_at ASC LIMIT 1)';
		$qb->select('s.id', 's.title', 's.capacity', 's.sold')
			->selectAlias($qb->createFunction($member), 'starts_at')
			->selectAlias($qb->createFunction($checkedIn('series', 's.id')), 'checked_in')
			->from('bc_series', 's')
			->where($qb->expr()->isNotNull($qb->createFunction($member)))
			->setMaxResults(200);
		if ($scope !== null) {
			$qb->andWhere($qb->expr()->in('s.offering_id',
				$qb->createNamedParameter($scope, $intArr)));
		}
		foreach ($qb->executeQuery()->fetchAll() as $r) {
			$r['type'] = 'series';
			$rows[] = $r;
		}

		usort($rows, fn (array $a, array $b): int =>
			strnatcmp((string)$a['starts_at'], (string)$b['starts_at']));
		return $this->ok(['stats' => array_slice($rows, 0, 200)]);
	}

	// ---------------------------------------------------------------
	// Scope + target helpers
	// ---------------------------------------------------------------

	/** @return list<int>|null null = unrestricted device */
	/**
	 * NH-28 — wallet pass admission. The device resolves the signed
	 * serial offline-capable (HMAC over serial), then claims the use
	 * atomically — a replayed pass code can never admit twice.
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function admitPass(): JSONResponse
	{
		return $this->admitPassPayload((string)$this->request->getParam('payload', ''));
	}

	private function admitPassPayload(string $payload): JSONResponse
	{
		if ($this->passes === null || !preg_match('/^BCK1P-[a-f0-9]{48}$/', $payload)) {
			return $this->fail(400, 'BAD_QR', 'Not a BookingCheck pass.');
		}
		$type = (string)$this->request->getParam('target_type', 'event');
		$tid = $this->strictId($this->request->getParam('target_id'));
		if (!in_array($type, ['event', 'slot', 'series'], true) || $tid === null) {
			return $this->fail(400, 'VALIDATION', 'target_type and target_id required for pass admission.');
		}
		$targetOffering = $this->offeringIdForTarget($type, $tid);
		if ($targetOffering === null || !$this->scopeAllows($targetOffering)) {
			return $this->notFound();
		}
		$res = $this->passes->admit($payload, $type, $tid, $targetOffering,
			'device:' . ($this->companionDevice?->getLabel() ?? 'unknown'));
		if (!$res['ok']) {
			return match ($res['reason']) {
				'already_admitted' => $this->fail(409, 'PASS_USED', 'This pass was already admitted here.'),
				'exhausted' => $this->fail(409, 'PASS_EXHAUSTED', 'Pass use limit reached.'),
				'outside_validity' => $this->fail(409, 'PASS_EXPIRED', 'Pass is not valid right now.'),
				'out_of_scope' => $this->fail(409, 'PASS_SCOPE', 'Pass does not cover this offering.'),
				default => $this->notFound(),
			};
		}
		return $this->ok(['admitted' => true, 'holder' => $res['holder']]);
	}

	/**
	 * NH-12 — door walk-up sale. Creates a PAID zero-friction order
	 * (payMethod walkup, no PSP roundtrip) for a scoped target. Buyer
	 * contact is optional — a door sale may be anonymous, the ticket is
	 * printed/handed, no manage link is mailed.
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function walkup(): JSONResponse
	{
		if (!in_array($this->companionDevice?->getRole(), ['door', 'manager', 'api'], true)) {
			return $this->fail(403, 'FORBIDDEN', 'Device role cannot sell.');
		}
		if ($this->orderService === null) {
			return $this->fail(400, 'UNSUPPORTED', 'Walk-up sales unavailable.');
		}
		$type = (string)$this->request->getParam('target_type', '');
		$tid = $this->strictId($this->request->getParam('target_id'));
		// strict seats too — (int)"2.9"/"3abc" silently truncates a door sale
		$seats = $this->strictId($this->request->getParam('seats'));
		if (!in_array($type, ['event', 'slot', 'series'], true) || $tid === null
			|| $seats === null || $seats > 50) {
			return $this->fail(400, 'VALIDATION', 'target_type, target_id and 1–50 seats required.');
		}
		$offeringId = $this->offeringIdForTarget($type, $tid);
		if ($offeringId === null || !$this->scopeAllows($offeringId)) {
			return $this->notFound();
		}
		// device supplies an idem_token so a retry after a dropped
		// connection replays instead of double-selling (NN-idempotency)
		$idem = trim((string)$this->request->getParam('idem_token', ''));
		if ($idem !== '' && !preg_match('/^[A-Za-z0-9_-]{8,36}$/', $idem)) {
			return $this->fail(400, 'VALIDATION', 'idem_token must be 8–36 url-safe chars.');
		}
		try {
			$result = $this->orderService->createWalkupOrder(
				$offeringId, $type, $tid, $seats,
				'device:' . ($this->companionDevice?->getLabel() ?? 'unknown'),
				$idem !== '' ? $idem : null);
		} catch (\OCA\BookingCheck\Exception\ValidationException $e) {
			return $this->fail(400, 'VALIDATION', 'Invalid walk-up request.', $e->getFields());
		} catch (\OCA\BookingCheck\Exception\CapacityException) {
			return $this->fail(409, 'CAPACITY', 'Not enough seats left.');
		} catch (\OCA\BookingCheck\Exception\NotFoundException) {
			return $this->notFound();
		}
		return $this->ok([
			'order_ref' => $result['order']->getRef(),
			'amount' => $result['order']->getAmountCents(),
			'currency' => $result['order']->getCurrency(),
		]);
	}

	/**
	 * NH-26 — register a push target on this device. Token is a device
	 * secret; store it server-side and keep it out of logs/serialization.
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function registerPush(): JSONResponse
	{
		if ($this->companions === null || $this->companionDevice === null) {
			return $this->notFound();
		}
		$token = trim((string)$this->request->getParam('token', ''));
		$kind = (string)$this->request->getParam('kind', '');
		// 'expo' is the only live transport today (PushService fans out via
		// the Expo Push API) — registering an fcm/apns kind would store a
		// token nothing can deliver to, so refuse it honestly.
		if ($token === '' || mb_strlen($token) > 512 || $kind !== 'expo') {
			return $this->fail(400, 'VALIDATION', 'token (≤512 chars) and kind "expo" required.');
		}
		$this->companionDevice->setPushToken($token);
		$this->companionDevice->setPushKind($kind);
		$this->companions->update($this->companionDevice);
		return $this->ok(['registered' => true]);
	}

	/**
	 * NH-04 write API — `api` role devices create a simple published
	 * event offering (single event, fixed capacity). Deliberately narrow:
	 * complex offerings go through the web UI.
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function createOffering(): JSONResponse
	{
		if ($this->companionDevice?->getRole() !== 'api') {
			return $this->fail(403, 'FORBIDDEN', 'API devices only.');
		}
		$title = trim((string)$this->request->getParam('title', ''));
		// strict digit parse — (int) would turn "abc" into a free offering
		// and "2.9" into capacity 2 silently
		$rawPrice = $this->request->getParam('price_cents') ?? '0';
		$rawCap = $this->request->getParam('capacity') ?? '';
		$rawDur = $this->request->getParam('duration_min') ?? '60';
		$priceCents = is_scalar($rawPrice) && preg_match('/^\d+$/', (string)$rawPrice)
			? (int)$rawPrice : -1;
		$capacity = is_scalar($rawCap) && preg_match('/^\d+$/', (string)$rawCap)
			? (int)$rawCap : 0;
		$duration = is_scalar($rawDur) && preg_match('/^\d+$/', (string)$rawDur)
			? max(15, min(1440, (int)$rawDur)) : 0;
		$startsAt = trim((string)$this->request->getParam('starts_at', ''));
		$fields = [];
		if ($title === '' || mb_strlen($title) > 190) {
			$fields['title'] = 'Title required (≤190 chars).';
		}
		if ($priceCents < 0 || $priceCents > 100000000) {
			$fields['price_cents'] = 'Price out of range.';
		}
		if ($capacity < 1 || $capacity > 100000) {
			$fields['capacity'] = 'Capacity 1–100000 required.';
		}
		if ($duration === 0) {
			$fields['duration_min'] = 'Duration must be a whole number of minutes.';
		}
		$start = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $startsAt)
			?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startsAt);
		if ($start === false || $start < new \DateTimeImmutable('now')) {
			$fields['starts_at'] = 'Future ATOM datetime required.';
			return $this->fail(400, 'VALIDATION', 'Invalid offering.', $fields);
		}
		if ($fields !== []) {
			return $this->fail(400, 'VALIDATION', 'Invalid offering.', $fields);
		}
		if ($this->orderService === null) {
			return $this->fail(400, 'UNSUPPORTED', 'Write API unavailable.');
		}
		[$offeringId, $eventId] = $this->orderService->createQuickOffering(
			$title, $priceCents, $capacity, $start, $duration,
			'device:' . ($this->companionDevice?->getLabel() ?? 'unknown'));
		return $this->ok(['offering_id' => $offeringId, 'event_id' => $eventId], 201);
	}

	/**
	 * NH-04 — manager quick-refund surface. Recent orders inside the
	 * device scope, newest first; `limit` clamps 1–100. Door-role
	 * devices are refused outright — a tablet at the gate must never
	 * enumerate a seller's order book.
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function orders(): JSONResponse
	{
		if ($this->companionDevice?->getRole() !== 'manager') {
			return $this->fail(403, 'FORBIDDEN', 'Manager devices only.');
		}
		if ($this->orderMapper === null) {
			return $this->fail(400, 'UNSUPPORTED', 'Orders unavailable.');
		}
		$limit = $this->strictId($this->request->getParam('limit')) ?? 50;
		$limit = max(1, min($limit, 100));
		$rows = array_map(
			$this->orderPayload(...),
			$this->orderMapper->findRecentScoped($this->deviceScope(), $limit));
		return $this->ok(['orders' => $rows]);
	}

	/** Manager order detail — scoped; foreign offerings collapse to 404. */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function order(int $id): JSONResponse
	{
		if ($this->companionDevice?->getRole() !== 'manager') {
			return $this->fail(403, 'FORBIDDEN', 'Manager devices only.');
		}
		$order = $this->scopedOrder($id);
		if ($order === null) {
			return $this->notFound();
		}
		return $this->ok(['order' => $this->orderPayload($order)]);
	}

	/**
	 * Manager quick refund — same two-step quote→echo contract as the
	 * web surface. The quote is the settled tranche the PSP can move;
	 * commit requires it echoed back so the app cannot blind-refund.
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function orderRefundQuote(int $id): JSONResponse
	{
		if ($this->companionDevice?->getRole() !== 'manager') {
			return $this->fail(403, 'FORBIDDEN', 'Manager devices only.');
		}
		$order = $this->scopedOrder($id);
		if ($order === null || $this->refunds === null) {
			return $order === null ? $this->notFound() : $this->fail(400, 'UNSUPPORTED', 'Refunds unavailable.');
		}
		try {
			return $this->ok($this->refunds->quote($order));
		} catch (\OCA\BookingCheck\Exception\ValidationException $e) {
			return $this->fail(400, 'VALIDATION', 'Order cannot be refunded.', $e->getFields());
		}
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function orderRefund(int $id): JSONResponse
	{
		if ($this->companionDevice?->getRole() !== 'manager') {
			return $this->fail(403, 'FORBIDDEN', 'Manager devices only.');
		}
		$order = $this->scopedOrder($id);
		if ($order === null || $this->refunds === null) {
			return $order === null ? $this->notFound() : $this->fail(400, 'UNSUPPORTED', 'Refunds unavailable.');
		}
		try {
			return $this->ok($this->refunds->refund(
				$order,
				$this->strictId($this->request->getParam('amount_cents')),
				'device:' . ($this->companionDevice?->getLabel() ?? 'unknown')));
		} catch (\OCA\BookingCheck\Exception\ValidationException $e) {
			return $this->fail(400, 'VALIDATION', 'Order cannot be refunded.', $e->getFields());
		} catch (\OCA\BookingCheck\Exception\StateConflictException) {
			return $this->fail(409, 'CONFLICT', 'A refund is already in progress for this order — retry in a moment.');
		} catch (\OCA\BookingCheck\Exception\PspException $e) {
			return $this->fail(502, 'PAYMENT_UNAVAILABLE', 'Payment is temporarily unavailable — please try again.');
		}
	}

	/** Scoped order lookup — the offering boundary is the tenant wall. */
	private function scopedOrder(int $id): ?\OCA\BookingCheck\Db\Order
	{
		$order = $this->orderMapper?->find($id);
		if ($order === null || !$this->scopeAllows((int)$order->getOfferingId())) {
			return null;
		}
		return $order;
	}

	/**
	 * Trimmed order payload — the manager needs enough to identify and
	 * refund an order, not the order's internal bookkeeping (staffNote,
	 * consent record, voucher wiring).
	 */
	private function orderPayload(\OCA\BookingCheck\Db\Order $o): array
	{
		return [
			'id' => (int)$o->getId(),
			'ref' => $o->getRef(),
			'offering_id' => (int)$o->getOfferingId(),
			'buyer_name' => $o->getBuyerName(),
			'buyer_email' => $o->getBuyerEmail(),
			'status' => $o->getStatus(),
			'amount_cents' => (int)$o->getAmountCents(),
			'currency' => $o->getCurrency(),
			'is_test' => (int)$o->getIsTest(),
			'paid_at' => $o->getPaidAt()?->format(\DateTimeInterface::ATOM),
			'created_at' => $o->getCreatedAt()->format(\DateTimeInterface::ATOM),
		];
	}

	/** @return list<int>|null null = unscoped; [] = malformed, grant nothing */
	private function deviceScope(): ?array
	{
		$raw = $this->companionDevice?->getOfferingIds();
		if ($raw === null || $raw === '') {
			return null;
		}
		$ids = json_decode((string)$raw, true);
		if (!is_array($ids)) {
			return []; // malformed scope = access to nothing, never widen
		}
		return array_values(array_map('intval', $ids));
	}

	private function scopeAllows(int $offeringId): bool
	{
		$scope = $this->deviceScope();
		return $scope === null || in_array($offeringId, $scope, true);
	}

	/** @return array{0:string,1:int}|null [target_type, offering_id] */
	private function resolveTarget(int $id): ?array
	{
		foreach (['event' => 'bc_event', 'slot' => 'bc_slot'] as $type => $table) {
			$v = $this->offeringIdForTarget($type, $id);
			if ($v !== null) {
				return [$type, $v];
			}
		}
		return null;
	}

	private function offeringIdForTarget(string $type, int $id): ?int
	{
		$table = ['event' => 'bc_event', 'slot' => 'bc_slot', 'series' => 'bc_series'][$type] ?? null;
		if ($table === null) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$v = $qb->select('offering_id')->from($table)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->executeQuery()->fetchOne();
		return $v === false ? null : (int)$v;
	}

	/**
	 * Resolve a door target to [type, line_target_id, offering_id].
	 * A series member event carries no order lines — orders target the
	 * series — so it redirects to its series and re-resolves ownership
	 * on the series itself (a scope check on the member alone could be
	 * satisfied while the series belongs to a foreign offering).
	 *
	 * @return array{0:string,1:int,2:int}|null
	 */
	private function resolveDoorTarget(string $type, int $id): ?array
	{
		if ($type === 'event') {
			$qb = $this->db->getQueryBuilder();
			$row = $qb->select('offering_id', 'series_id')->from('bc_event')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
				->executeQuery()->fetch();
			if ($row === false) {
				return null;
			}
			if ($row['series_id'] !== null) {
				$sid = (int)$row['series_id'];
				$oid = $this->offeringIdForTarget('series', $sid);
				return $oid === null ? null : ['series', $sid, $oid];
			}
			return ['event', $id, (int)$row['offering_id']];
		}
		$oid = $this->offeringIdForTarget($type, $id);
		return $oid === null ? null : [$type, $id, $oid];
	}
}

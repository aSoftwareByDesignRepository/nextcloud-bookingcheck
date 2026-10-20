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
	public $companionDevice = null;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly TicketService $tickets,
		private readonly IDBConnection $db,
		private readonly ?\OCA\BookingCheck\Service\PassService $passes = null,
		private readonly ?\OCA\BookingCheck\Service\OrderService $orderService = null,
		private readonly ?\OCA\BookingCheck\Db\CompanionTokenMapper $companions = null,
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
				'manager' => ['events', 'roster', 'check-in', 'stats', 'push', 'pass', 'walkup'],
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
		$qb = $this->db->getQueryBuilder();
		$qb->select('e.id', 'e.starts_at', 'e.ends_at', 'e.capacity', 'e.sold',
			'o.title', 'o.id AS offering_id')
			->from('bc_event', 'e')
			->innerJoin('e', 'bc_offering', 'o', $qb->expr()->eq('o.id', 'e.offering_id'))
			->where($qb->expr()->eq('e.status', $qb->createNamedParameter('scheduled')))
			->andWhere($qb->expr()->gte('e.starts_at',
				$qb->createNamedParameter(new \DateTime('-1 day'), 'datetime')))
			->orderBy('e.starts_at', 'ASC')
			->setMaxResults(200);
		if ($scope !== null) {
			$qb->andWhere($qb->expr()->in('o.id',
				$qb->createNamedParameter($scope, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
		}
		return $this->ok(['events' => $qb->executeQuery()->fetchAll()]);
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
		$qb2 = $this->db->getQueryBuilder();
		$offeringId = $qb2->select('o.offering_id')->from('bc_order_line', 'ol')
			->innerJoin('ol', 'bc_order', 'o', $qb2->expr()->eq('o.id', 'ol.order_id'))
			->where($qb2->expr()->eq('ol.id', $qb2->createNamedParameter((int)$ticket['line_id'])))
			->executeQuery()->fetchOne();
		if ($offeringId === false || !$this->scopeAllows((int)$offeringId)) {
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
		$qb = $this->db->getQueryBuilder();
		$qb->select('e.id', 'o.title', 'e.capacity', 'e.sold')
			->selectAlias($qb->createFunction(
				'(SELECT COUNT(*) FROM *PREFIX*bc_ticket t
				  JOIN *PREFIX*bc_order_line ol ON ol.id = t.line_id
				  WHERE ol.target_type = ' . $qb->createNamedParameter('event') . '
				    AND ol.target_id = e.id
				    AND t.checked_in_at IS NOT NULL
				    AND t.revoked_at IS NULL)'), 'checked_in')
			->from('bc_event', 'e')
			->innerJoin('e', 'bc_offering', 'o', $qb->expr()->eq('o.id', 'e.offering_id'))
			->where($qb->expr()->eq('e.status', $qb->createNamedParameter('scheduled')))
			->andWhere($qb->expr()->gte('e.starts_at',
				$qb->createNamedParameter(new \DateTime('-1 day'), 'datetime')))
			->orderBy('e.starts_at', 'ASC')
			->setMaxResults(200);
		if ($scope !== null) {
			$qb->andWhere($qb->expr()->in('o.id',
				$qb->createNamedParameter($scope, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
		}
		return $this->ok(['stats' => $qb->executeQuery()->fetchAll()]);
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
		$seats = (int)($this->request->getParam('seats') ?? 0);
		if (!in_array($type, ['event', 'slot', 'series'], true) || $tid === null
			|| $seats < 1 || $seats > 50) {
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
		$priceCents = (int)($this->request->getParam('price_cents') ?? 0);
		$capacity = (int)($this->request->getParam('capacity') ?? 0);
		$startsAt = trim((string)$this->request->getParam('starts_at', ''));
		$duration = max(15, min(1440, (int)($this->request->getParam('duration_min') ?? 60)));
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
		$start = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $startsAt)
			?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startsAt);
		if ($start === false || $start < new \DateTimeImmutable('now')) {
			$fields['starts_at'] = 'Future ATOM datetime required.';
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

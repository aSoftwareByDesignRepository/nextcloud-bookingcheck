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
			'capabilities' => $this->companionDevice?->getRole() === 'manager'
				? ['events', 'roster', 'check-in', 'stats'] : ['events', 'roster', 'check-in'],
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

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

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
 * is injected by the middleware. Door-role devices get check-in only.
 */
class CapiController extends Controller
{
	use ApiControllerTrait;

	/** @var \OCA\BookingCheck\Db\CompanionToken|null injected by middleware */
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
		return $this->ok(['events' => $qb->executeQuery()->fetchAll()]);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function roster(int $id): JSONResponse
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('t.id', 't.attendee_name', 't.seat_no', 't.checked_in_at')
			->from('bc_ticket', 't')
			->innerJoin('t', 'bc_order_line', 'ol', $qb->expr()->eq('ol.id', 't.line_id'))
			->innerJoin('ol', 'bc_order', 'o', $qb->expr()->eq('o.id', 'ol.order_id'))
			->where($qb->expr()->eq('ol.target_id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('o.status', $qb->createNamedParameter('paid')))
			->orderBy('t.attendee_name', 'ASC');
		return $this->ok(['roster' => $qb->executeQuery()->fetchAll()]);
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
		$result = $this->tickets->checkInByToken($m[1],
			'device:' . ($this->companionDevice?->getLabel() ?? 'unknown'));
		if (!$result['ok']) {
			return $this->fail(404, 'NOT_FOUND', 'Unknown ticket.');
		}
		return $this->ok([
			'checked_in' => true,
			'already' => $result['already'],
			'attendee' => $result['ticket']->getAttendeeName(),
			'seat' => $result['ticket']->getSeatNo(),
		]);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function undoCheckIn(): JSONResponse
	{
		$id = $this->strictId($this->request->getParam('ticket_id'));
		if ($id === null) {
			return $this->fail(400, 'VALIDATION', 'ticket_id required.');
		}
		$this->tickets->undoCheckIn($id);
		return $this->ok(['checked_in' => false]);
	}
}

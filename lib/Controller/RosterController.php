<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\TicketMapper;
use OCA\BookingCheck\Exception\NotFoundException;
use OCA\BookingCheck\Service\TicketService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;

class RosterController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly TicketMapper $tickets,
		private readonly TicketService $ticketService,
		private readonly IDBConnection $db,
		private readonly IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse
	{
		// picker data: slots+events labelled with their offering title
		$qb = $this->db->getQueryBuilder();
		$qb->select('s.id', 's.start_utc', 's.capacity', 's.sold_qty', 'f.title')
			->from('bc_slot', 's')
			->innerJoin('s', 'bc_offering', 'f', $qb->expr()->eq('f.id', 's.offering_id'))
			->where($qb->expr()->neq('f.status', $qb->createNamedParameter('archived')))
			->orderBy('s.start_utc', 'ASC')
			->setMaxResults(200);
		$slots = $qb->executeQuery()->fetchAll();
		$qb = $this->db->getQueryBuilder();
		$qb->select('e.id', 'e.start_utc', 'e.capacity', 'e.sold_qty', 'f.title')
			->from('bc_event', 'e')
			->innerJoin('e', 'bc_offering', 'f', $qb->expr()->eq('f.id', 'e.offering_id'))
			->where($qb->expr()->neq('f.status', $qb->createNamedParameter('archived')))
			->orderBy('e.start_utc', 'ASC')
			->setMaxResults(200);
		$events = $qb->executeQuery()->fetchAll();
		return new TemplateResponse($this->appName, 'admin/roster', [
			'navId' => 'roster',
			'occurrences' => array_merge($events, $slots),
		]);
	}

	/** Roster for a slot/event — tickets joined through their lines/orders. */
	#[NoAdminRequired]
	public function slot(int $id): JSONResponse
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('t.id', 't.seat_no', 't.attendee_name', 't.attendee_email',
			't.checked_in_at', 't.checked_in_by', 'o.ref AS order_ref', 'o.status AS order_status')
			->from('bc_ticket', 't')
			->innerJoin('t', 'bc_order_line', 'ol', $qb->expr()->eq('ol.id', 't.line_id'))
			->innerJoin('ol', 'bc_order', 'o', $qb->expr()->eq('o.id', 'ol.order_id'))
			->where($qb->expr()->eq('ol.target_id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('o.status', $qb->createNamedParameter('paid')))
			->orderBy('t.attendee_name', 'ASC');
		return $this->ok(['roster' => $qb->executeQuery()->fetchAll()]);
	}

	#[NoAdminRequired]
	public function checkIn(int $id): JSONResponse
	{
		$ticket = $this->tickets->find($id);
		if ($ticket === null) {
			throw new NotFoundException();
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_ticket')
			->set('checked_in_at', $qb->createNamedParameter(new \DateTime(), 'datetime'))
			->set('checked_in_by', $qb->createNamedParameter($this->userSession->getUser()?->getUID() ?? 'admin'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->isNull('checked_in_at'));
		$won = $qb->executeStatement() === 1;
		return $this->ok(['checked_in' => true, 'already' => !$won]);
	}

	#[NoAdminRequired]
	public function undoCheckIn(int $id): JSONResponse
	{
		$ticket = $this->tickets->find($id);
		if ($ticket === null) {
			throw new NotFoundException();
		}
		$this->ticketService->undoCheckIn($id);
		return $this->ok(['checked_in' => false]);
	}
}

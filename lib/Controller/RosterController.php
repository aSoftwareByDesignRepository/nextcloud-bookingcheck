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
		// picker data: slots+events labelled with their offering title.
		// `type` rides along — event and slot ids share a numeric space.
		$occurrences = [];
		foreach (['event' => 'bc_event', 'slot' => 'bc_slot'] as $type => $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('t.id', 't.starts_at', 't.capacity', 't.sold', 'f.title')
				->from($table, 't')
				->innerJoin('t', 'bc_offering', 'f', $qb->expr()->eq('f.id', 't.offering_id'))
				->where($qb->expr()->neq('f.status', $qb->createNamedParameter('archived')))
				->orderBy('t.starts_at', 'ASC')
				->setMaxResults(200);
			foreach ($qb->executeQuery()->fetchAll() as $row) {
				$row['type'] = $type;
				$occurrences[] = $row;
			}
		}
		usort($occurrences, fn ($a, $b) => strcmp((string)$a['starts_at'], (string)$b['starts_at']));
		return new TemplateResponse($this->appName, 'admin/roster', [
			'navId' => 'roster',
			'occurrences' => $occurrences,
		]);
	}

	/** Roster for a slot/event — tickets joined through their lines/orders.
	 *  Target type is explicit: event and slot ids share a numeric space,
	 *  and event/series lines both reference bc_order_line.target_id. */
	#[NoAdminRequired]
	public function roster(string $type, int $id): JSONResponse
	{
		if (!in_array($type, ['event', 'slot', 'series'], true)) {
			throw new NotFoundException();
		}
		// series member events carry no lines — orders target the series
		if ($type === 'event') {
			$sq = $this->db->getQueryBuilder();
			$sid = $sq->select('series_id')->from('bc_event')
				->where($sq->expr()->eq('id', $sq->createNamedParameter($id)))
				->executeQuery()->fetchOne();
			if ($sid !== false && $sid !== null) {
				$type = 'series';
				$id = (int)$sid;
			}
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('t.id', 't.seat_no', 't.attendee_name', 't.attendee_email',
			't.checked_in_at', 't.checked_in_by', 'o.ref AS order_ref', 'o.status AS order_status')
			->from('bc_ticket', 't')
			->innerJoin('t', 'bc_order_line', 'ol', $qb->expr()->eq('ol.id', 't.line_id'))
			->innerJoin('ol', 'bc_order', 'o', $qb->expr()->eq('o.id', 'ol.order_id'))
			->where($qb->expr()->eq('ol.target_id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('ol.target_type', $qb->createNamedParameter($type)))
			->andWhere($qb->expr()->eq('o.status', $qb->createNamedParameter('paid')))
			->andWhere($qb->expr()->isNull('t.revoked_at'))
			->orderBy('t.attendee_name', 'ASC');
		$roster = $qb->executeQuery()->fetchAll();
		// intake answers the roster is allowed to see (visible_to = roster|door)
		$ids = array_map(fn ($r) => (int)$r['id'], $roster);
		if ($ids !== []) {
			$aq = $this->db->getQueryBuilder();
			$aq->select('a.ticket_id', 'a.value', 'q.label')
				->from('bc_answer', 'a')
				->innerJoin('a', 'bc_question', 'q', $aq->expr()->eq('q.id', 'a.question_id'))
				->where($aq->expr()->in('a.ticket_id',
					$aq->createNamedParameter($ids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($aq->expr()->in('q.visible_to',
					$aq->createNamedParameter(['admin', 'roster', 'door'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
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

	#[NoAdminRequired]
	public function checkIn(int $id): JSONResponse
	{
		$ticket = $this->tickets->find($id);
		if ($ticket === null) {
			throw new NotFoundException();
		}
		if ($ticket->getRevokedAt() !== null) {
			return $this->fail(409, 'TICKET_REVOKED', 'This ticket was cancelled or refunded.');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_ticket')
			->set('checked_in_at', $qb->createNamedParameter(new \DateTime(), 'datetime'))
			->set('checked_in_by', $qb->createNamedParameter($this->userSession->getUser()?->getUID() ?? 'admin'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->isNull('checked_in_at'))
			->andWhere($qb->expr()->isNull('revoked_at')); // CAS guard — no race with refund
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

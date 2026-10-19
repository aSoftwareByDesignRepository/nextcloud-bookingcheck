<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\OrderMapper;
use OCA\BookingCheck\Service\TicketService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUserSession;

/** Web door scanner — admin session only, camera-based check-in. */
class ScanController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly TicketService $tickets,
		private readonly OrderMapper $orders,
		private readonly IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse
	{
		return new TemplateResponse($this->appName, 'admin/scan', ['navId' => 'scan']);
	}

	#[NoAdminRequired]
	public function checkIn(): JSONResponse
	{
		$qr = $this->qrParam();
		if (!preg_match('/^BCK1:\d+:([A-Za-z0-9_-]{32,64})$/', $qr, $m)) {
			return $this->fail(400, 'BAD_QR', 'Not a BookingCheck ticket.');
		}
		$result = $this->tickets->checkInByToken($m[1],
			$this->userSession->getUser()?->getUID() ?? 'scan');
		if (!$result['ok']) {
			if ($result['revoked'] ?? false) {
				return $this->fail(409, 'TICKET_REVOKED', 'This ticket was cancelled or refunded.');
			}
			return $this->fail(404, 'NOT_FOUND', 'Unknown ticket.');
		}
		return $this->ok([
			'checked_in' => true,
			'already' => $result['already'],
			'ticket_id' => (int)$result['ticket']->getId(),
			'attendee_name' => $result['ticket']->getAttendeeName(),
		]);
	}

	/**
	 * Read-only resolve — the scan page previews a ticket, then the door
	 * confirms via the roster check-in. Must NEVER mutate: a "lookup" that
	 * checks people in silently turns every preview into a side effect.
	 *
	 * Accepts the QR payload (BCK1:<id>:<token>) OR the order reference
	 * (BCK-YYYY-XXXXXXXX) printed on the ticket — a camera-free door types
	 * the ref and gets the booking's ticket list.
	 */
	#[NoAdminRequired]
	public function lookup(): JSONResponse
	{
		$input = $this->qrParam();
		if (preg_match('/^BCK1:\d+:([A-Za-z0-9_-]{32,64})$/', $input, $m)) {
			$ticket = $this->tickets->resolveQrToken($m[1]);
			if ($ticket === null) {
				return $this->fail(404, 'NOT_FOUND', 'Unknown ticket.');
			}
			if ($ticket->getRevokedAt() !== null) {
				return $this->fail(409, 'TICKET_REVOKED', 'This ticket was cancelled or refunded.');
			}
			return $this->ok([
				'ticket_id' => (int)$ticket->getId(),
				'attendee_name' => $ticket->getAttendeeName(),
				'checked_in' => $ticket->getCheckedInAt() !== null,
			]);
		}
		if (preg_match('/^BCK-\d{4}-[A-Z0-9]{8}$/i', $input)) {
			$order = $this->orders->findByRef(strtoupper($input));
			if ($order === null || $order->getStatus() !== 'paid') {
				return $this->fail(404, 'NOT_FOUND', 'No valid booking for this code.');
			}
			$list = $this->tickets->liveTicketsForOrder((int)$order->getId());
			if ($list === []) {
				return $this->fail(404, 'NOT_FOUND', 'No valid booking for this code.');
			}
			return $this->ok(['order_ref' => $order->getRef(), 'tickets' => $list]);
		}
		return $this->fail(400, 'BAD_QR', 'Not a BookingCheck ticket or order reference.');
	}

	/** The scan form posts `code`; older clients post `qr`. */
	private function qrParam(): string
	{
		$qr = (string)$this->request->getParam('code', '');
		return $qr !== '' ? $qr : (string)$this->request->getParam('qr', '');
	}
}

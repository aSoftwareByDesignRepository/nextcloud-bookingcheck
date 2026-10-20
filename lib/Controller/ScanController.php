<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\CompanionToken;
use OCA\BookingCheck\Db\OrderMapper;
use OCA\BookingCheck\Db\Ticket;
use OCA\BookingCheck\Middleware\AppAccessMiddleware;
use OCA\BookingCheck\Service\DoorSessionService;
use OCA\BookingCheck\Service\TicketService;
use OCA\BookingCheck\Service\TokenService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Web door scanner — least-privileged door access.
 *
 * Two principals may reach this controller:
 *  - a logged-in NC user with the BookingCheck admin role (full scan UI
 *    inside the seller shell);
 *  - a door session minted from a `door`/`manager` companion token —
 *    tablets at the venue, no NC account, restricted to THIS controller
 *    and scoped to the token's offering list.
 *
 * The controller self-gates: AppAccessMiddleware exempts /scan + /api/scan
 * so anonymous door entry is reachable, and every endpoint asserts
 * door-or-admin itself. Nowhere else accepts a door session.
 */
class ScanController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly TicketService $tickets,
		private readonly OrderMapper $orders,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly DoorSessionService $doors,
		private readonly IDBConnection $db,
		private readonly TokenService $tokenService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * The scan page. Admin → full seller shell. Door session → minimal
	 * guest chrome with the scanner. Anonymous → the door-token entry
	 * form (the only public content this page ever shows).
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse
	{
		$device = $this->doors->device();
		if ($this->isAdmin()) {
			return new TemplateResponse($this->appName, 'admin/scan', [
				'navId' => 'scan', 'scanMode' => 'admin',
			], 'user');
		}
		return new TemplateResponse($this->appName, 'public/door', [
			'scanMode' => $device !== null ? 'door' : 'entry',
			'doorLabel' => $device?->getLabel(),
		], 'guest');
	}

	/**
	 * Mint a door session from a companion token. Bootstrap endpoint —
	 * the token itself is the credential; it binds only to the caller's
	 * own cookie so CSRF-forcing it on a victim grants the attacker
	 * nothing. Rate-limited by RateLimitMiddleware.
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function openSession(): JSONResponse
	{
		$token = (string)$this->request->getParam('token', '');
		if (!$this->doors->open($token)) {
			return $this->fail(401, 'UNAUTHORIZED', 'Invalid device token.');
		}
		return $this->ok(['door' => true]);
	}

	/** End a door session — shared-tablet hygiene. */
	#[PublicPage]
	#[NoAdminRequired]
	public function closeSession(): JSONResponse
	{
		$this->doors->close();
		return $this->ok(['door' => false]);
	}

	#[PublicPage]
	#[NoAdminRequired]
	public function checkIn(): JSONResponse
	{
		$device = $this->assertDoorOrAdmin();
		$qr = $this->qrParam();
		if (!preg_match('/^BCK1:\d+:([A-Za-z0-9_-]{32,64})$/', $qr, $m)) {
			return $this->fail(400, 'BAD_QR', 'Not a BookingCheck ticket.');
		}
		if ($device !== null && !$this->doorScopeOk($device, $m[1])) {
			return $this->fail(404, 'NOT_FOUND', 'Unknown ticket.');
		}
		$actor = $device !== null
			? 'door:' . (int)$device->getId()
			: ($this->userSession->getUser()?->getUID() ?? 'scan');
		$result = $this->tickets->checkInByToken($m[1], $actor);
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
	#[PublicPage]
	#[NoAdminRequired]
	public function lookup(): JSONResponse
	{
		$device = $this->assertDoorOrAdmin();
		$input = $this->qrParam();
		if (preg_match('/^BCK1:\d+:([A-Za-z0-9_-]{32,64})$/', $input, $m)) {
			if ($device !== null && !$this->doorScopeOk($device, $m[1])) {
				return $this->fail(404, 'NOT_FOUND', 'Unknown ticket.');
			}
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
				// the door re-posts this payload for check-in — id-enumerated
				// check-ins stay impossible
				'qr' => $this->tickets->qrPayload($ticket),
			]);
		}
		if (preg_match('/^BCK-\d{4}-[A-Z0-9]{8}$/i', $input)) {
			$order = $this->orders->findByRef(strtoupper($input));
			if ($order === null || $order->getStatus() !== 'paid') {
				return $this->fail(404, 'NOT_FOUND', 'No valid booking for this code.');
			}
			if ($device !== null
				&& !$this->doors->scopeAllows($device, (int)$order->getOfferingId())) {
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

	/**
	 * The door gate: BookingCheck admin OR a live door session. Returns
	 * the door device when the session path was used — callers must still
	 * scope-check the target offering against the device. Anonymous and
	 * non-admin NC users get the same refusal.
	 */
	private function assertDoorOrAdmin(): ?CompanionToken
	{
		if ($this->isAdmin()) {
			return null;
		}
		$device = $this->doors->device();
		if ($device === null) {
			throw new \OCA\BookingCheck\Exception\UnauthorizedException();
		}
		return $device;
	}

	/** Ticket QR token → its offering id, via the order line. A ticket
	 *  outside the device's scope is indistinguishable from unknown. */
	private function doorScopeOk(CompanionToken $device, string $qrToken): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('ol.offering_id')
			->from('bc_ticket', 't')
			->innerJoin('t', 'bc_order_line', 'ol', $qb->expr()->eq('ol.id', 't.line_id'))
			->where($qb->expr()->eq('t.qr_token_hash',
				$qb->createNamedParameter($this->tokenService->hash($qrToken))));
		$offeringId = $qb->executeQuery()->fetchOne();
		if ($offeringId === false) {
			return false;
		}
		return $this->doors->scopeAllows($device, (int)$offeringId);
	}

	private function isAdmin(): bool
	{
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}
		$uid = $user->getUID();
		return $this->groupManager->isAdmin($uid)
			|| $this->groupManager->isInGroup($uid, AppAccessMiddleware::ADMIN_GROUP);
	}

	/** The scan form posts `code`; older clients post `qr`. */
	private function qrParam(): string
	{
		$qr = (string)$this->request->getParam('code', '');
		return $qr !== '' ? $qr : (string)$this->request->getParam('qr', '');
	}
}

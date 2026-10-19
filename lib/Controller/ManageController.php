<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\OrderLineMapper;
use OCA\BookingCheck\Db\OrderMapper;
use OCA\BookingCheck\Db\TicketMapper;
use OCA\BookingCheck\Exception\NotFoundException;
use OCA\BookingCheck\Service\EmailService;
use OCA\BookingCheck\Service\OrderService;
use OCA\BookingCheck\Service\TokenService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IURLGenerator;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IRequest;

/**
 * Bearer-token manage page (§9.4).
 *
 * The token IS the authorization: 256-bit, hashed at rest, uniform 404
 * on any miss. Only the legal actions for the current state render.
 */
class ManageController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly OrderMapper $orders,
		private readonly OrderLineMapper $lines,
		private readonly TicketMapper $tickets,
		private readonly \OCA\BookingCheck\Service\TicketService $ticketService,
		private readonly \OCA\BookingCheck\Service\PaySessionService $paySessions,
		private readonly OrderService $orderService,
		private readonly TokenService $tokens,
		private readonly EmailService $email,
		private readonly IURLGenerator $url,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(string $token): PublicTemplateResponse
	{
		$order = $this->resolve($token);
		if ($order === null) {
			return new PublicTemplateResponse($this->appName, 'public/not-found', [
				'recover' => true, // oracle-safe "send me a new link" affordance
			]);
		}
		$lineRows = array_map(fn ($l) => $l->jsonSerialize(), $this->lines->findByOrder((int)$order->getId()));
		$ticketsByLine = [];
		foreach ($lineRows as $ln) {
			foreach ($this->tickets->findByLine((int)$ln['id']) as $t) {
				$ticketsByLine[(int)$ln['id']][] = [
					'id' => (int)$t->getId(),
					'seat_no' => $t->getSeatNo() !== null ? (int)$t->getSeatNo() : null,
					'checked_in' => $t->getCheckedInAt() !== null,
				];
			}
		}
		return new PublicTemplateResponse($this->appName, 'public/manage', [
			'order' => [
				'ref' => $order->getRef(), 'status' => $order->getStatus(),
				'amount_cents' => (int)$order->getAmountCents(), 'currency' => $order->getCurrency(),
				'buyer_name' => $order->getBuyerName(),
			],
			'lines' => $lineRows,
			'token' => $token,
			'ticketsByLine' => $ticketsByLine,
			'actions' => $this->legalActions($order->getStatus()),
		]);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function cancel(string $token): JSONResponse
	{
		$order = $this->resolve($token);
		if ($order === null) {
			throw new NotFoundException();
		}
		if ($order->getStatus() === 'paid') {
			$this->orderService->transitionToCancelled((int)$order->getId(), 'buyer');
		} else {
			$this->orderService->cancelPending((int)$order->getId(), 'buyer');
		}
		return $this->ok(['status' => 'cancelled']);
	}

	/** ICS download for the booking — RFC5545-escaped at the encoder. */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function ics(string $token)
	{
		$order = $this->resolve($token);
		if ($order === null) {
			throw new NotFoundException();
		}
		$ics = \OCP\Server::get(\OCA\BookingCheck\Service\IcsService::class)->forOrder($order);
		$resp = new DataDownloadResponse($ics, 'booking-' . $order->getRef() . '.ics', 'text/calendar');
		return $resp;
	}

	/** One ticket page — QR SVG for the door, scoped to this order. */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function ticket(string $token, int $ticketId): PublicTemplateResponse
	{
		$order = $this->resolve($token);
		if ($order === null) {
			throw new NotFoundException();
		}
		$ticket = $this->tickets->find($ticketId);
		if ($ticket === null) {
			throw new NotFoundException();
		}
		// scope: the ticket's line must belong to THIS order — else uniform 404
		$line = $this->lines->find((int)$ticket->getLineId());
		if ($line === null || (int)$line->getOrderId() !== (int)$order->getId()) {
			throw new NotFoundException();
		}
		return new PublicTemplateResponse($this->appName, 'public/ticket', [
			'ticket' => [
				'id' => (int)$ticket->getId(),
				'name' => $ticket->getAttendeeName(),
				'seat_no' => $ticket->getSeatNo(),
				'resource' => $ticket->getResourceName(),
				'checked_in' => $ticket->getCheckedInAt() !== null,
			],
			'order' => ['ref' => $order->getRef()],
			'qrSvg' => $this->ticketService->qrSvg($ticket),
			'token' => $token,
		]);
	}

	/** "Pay now" for approved/ttl-extended orders — fresh PSP session. */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function pay(string $token): RedirectResponse
	{
		$order = $this->resolve($token);
		if ($order === null) {
			throw new NotFoundException();
		}
		$back = $this->url->linkToRouteAbsolute('bookingcheck.manage.show', ['token' => $token]);
		if (!in_array($order->getStatus(), ['pending_payment', 'approved'], true)) {
			return new RedirectResponse($back);
		}
		try {
			$session = $this->paySessions->createForOrder($order);
		} catch (\OCA\BookingCheck\Exception\PspException) {
			return new RedirectResponse($back);
		}
		return new RedirectResponse($session->checkoutUrl);
	}

	/**
	 * Lost-link recovery — uniform 202, sends only when the email matches
	 * a live order (per-email send cap inside EmailService).
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function recover(): JSONResponse
	{
		$email = strtolower(trim((string)$this->request->getParam('email', '')));
		if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
			// mint a fresh manage link for the newest live order of that
			// email — a new token, never the old hash's plaintext
			$order = $this->orders->findLatestLiveForEmail($email);
			if ($order !== null) {
				[$token, $hash] = $this->tokens->mint();
				$this->orders->rotateManageToken((int)$order->getId(), $hash);
				$this->email->queue('manage_link', $email, $order->getBuyerLocale(), [
					'order_ref' => $order->getRef(), 'token' => $token,
				], capped: true);
			}
		}
		return $this->ok(['sent' => true], 202); // uniform — no oracle
	}

	private function resolve(string $token): ?\OCA\BookingCheck\Db\Order
	{
		if ($token === '' || strlen($token) > 128) {
			return null;
		}
		$order = $this->orders->findByManageTokenHash($this->tokens->hash($token));
		if ($order === null) {
			return null;
		}
		// TTL: manage link dies 90d after the last booked occurrence ends
		$last = $this->lines->lastBookedEnd((int)$order->getId());
		if ($last !== null && $last < new \DateTimeImmutable('-90 days')) {
			return null;
		}
		return $order;
	}

	private function legalActions(string $status): array
	{
		return match ($status) {
			'paid' => ['cancel', 'ics', 'tickets'],
			'pending_payment', 'approved' => ['cancel', 'pay'],
			'pending_approval' => ['cancel'],
			default => [],
		};
	}
}

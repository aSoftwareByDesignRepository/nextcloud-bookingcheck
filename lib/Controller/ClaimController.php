<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Db\WaitlistEntryMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\IRequest;

/** Waitlist claim link — consumes an offer hold into a real checkout. */
class ClaimController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly WaitlistEntryMapper $waitlist,
		private readonly OfferingMapper $offerings,
		private readonly \OCA\BookingCheck\Service\TokenService $tokens,
		private readonly \OCA\BookingCheck\Service\WaitlistService $waitlistService,
		private readonly \OCA\BookingCheck\Service\OrderService $orders,
		private readonly \OCA\BookingCheck\Service\PaySessionService $paySessions,
		private readonly \OCP\IURLGenerator $url,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(string $token)
	{
		if ($token === '' || strlen($token) > 128) {
			return new PublicTemplateResponse($this->appName, 'public/not-found', []);
		}
		$row = $this->waitlist->findByClaimTokenHash($this->tokens->hash($token));
		if ($row === null || $row->getState() !== 'offered'
			|| ($row->getOfferExpiresAt() !== null && $row->getOfferExpiresAt() < new \DateTimeImmutable())) {
			return new PublicTemplateResponse($this->appName, 'public/not-found', []);
		}
		// expose the offering's terms so the claim page can demand consent
		$offeringId = $this->waitlistService->offeringForTarget($row->getTargetType(), (int)$row->getTargetId());
		$offering = $offeringId !== null ? $this->offerings->find($offeringId) : null;
		$tosText = trim((string)($offering?->getTosText() ?? ''));
		return new PublicTemplateResponse($this->appName, 'public/claim', [
			'target_type' => $row->getTargetType(),
			'target_id' => (int)$row->getTargetId(),
			'email' => $row->getEmail(),
			'token' => $token,
			'tosText' => $tosText,
		]);
	}

	/** Convert the offer into a real order — atomic claim + hold transfer. */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function accept(string $token): JSONResponse
	{
		$buyerName = trim((string)$this->request->getParam('buyer_name', ''));
		if ($buyerName === '' || mb_strlen($buyerName) > 190) {
			return new JSONResponse(['success' => false, 'error' => 'VALIDATION',
				'message' => 'Please check the highlighted fields.',
				'fields' => ['buyer_name' => 'Your name is required.']], 400);
		}
		$consented = in_array((string)$this->request->getParam('consent', ''), ['1', 'true'], true);
		// the waitlister's own locale — never force 'en' on a booked claim
		$row = $this->waitlist->findByClaimTokenHash($this->tokens->hash($token));
		$locale = $row !== null ? (string)$row->getLocale() : 'en';
		try {
			$result = $this->waitlistService->acceptClaim($token, $buyerName,
				$locale, $this->orders, $consented);
		} catch (\OCA\BookingCheck\Exception\NotFoundException) {
			throw new \OCA\BookingCheck\Exception\NotFoundException();
		} catch (\OCA\BookingCheck\Exception\CapacityException) {
			return new JSONResponse(['success' => false, 'error' => 'SOLD_OUT',
				'message' => 'The seat was just taken — you are back on the waitlist.',
				'fields' => []], 409);
		}
		$order = $result['order'];
		$base = ['success' => true, 'order_ref' => $order->getRef(), 'status' => $order->getStatus()];
		if ($result['action'] === 'pay') {
			try {
				$session = $this->paySessions->createForOrder($order);
			} catch (\OCA\BookingCheck\Exception\PspException) {
				$this->orders->cancelPending((int)$order->getId(), 'psp');
				return new JSONResponse(['success' => false, 'error' => 'PAYMENT_UNAVAILABLE',
					'message' => 'Payment is temporarily unavailable — please try again.', 'fields' => []], 502);
			}
			return new JSONResponse($base + ['action' => 'pay', 'checkout_url' => $session->checkoutUrl], 201);
		}
		$manage = $result['manageToken'] !== ''
			? $this->url->linkToRouteAbsolute('bookingcheck.manage.show', ['token' => $result['manageToken']])
			: null;
		return new JSONResponse($base + ['action' => $result['action'] === 'approve' ? 'approval' : 'done',
			'manage_url' => $manage], 201);
	}
}

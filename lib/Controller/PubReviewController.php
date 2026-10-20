<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Db\OrderLineMapper;
use OCA\BookingCheck\Db\OrderMapper;
use OCA\BookingCheck\Db\Review;
use OCA\BookingCheck\Db\ReviewMapper;
use OCA\BookingCheck\Service\TokenService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/** Verified-attendee reviews — one per order line, moderated. */
class PubReviewController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ReviewMapper $reviews,
		private readonly OrderLineMapper $lines,
		private readonly OrderMapper $orders,
		private readonly OfferingMapper $offerings,
		private readonly TokenService $tokens,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function store(): JSONResponse
	{
		$token = (string)$this->request->getParam('manage_token', '');
		$lineId = $this->strictId($this->request->getParam('line_id'));
		$rating = $this->strictId($this->request->getParam('rating'));
		$text = trim((string)$this->request->getParam('text', ''));
		if ($lineId === null || $rating === null || $rating < 1 || $rating > 5) {
			return $this->fail(400, 'VALIDATION', 'Pick a rating 1–5.');
		}
		// capability check: the manage token IS the authorization. A bare
		// line_id is enumerable — without the token anyone could post
		// reviews on a stranger's paid booking.
		if ($token === '' || strlen($token) > 128) {
			return $this->notFound();
		}
		$order = $this->orders->findByManageTokenHash($this->tokens->hash($token));
		if ($order === null || $order->getStatus() !== 'paid') {
			return $this->notFound();
		}
		$line = $this->lines->find($lineId);
		if ($line === null || (int)$line->getOrderId() !== (int)$order->getId()) {
			return $this->notFound(); // foreign line — same uniform answer
		}
		$offering = $this->offerings->find((int)$order->getOfferingId());
		if ($offering === null || (int)$offering->getReviewDisplay() !== 1) {
			return $this->notFound(); // reviews disabled for this offering
		}
		$r = new Review();
		$r->setLineId($lineId);
		$r->setRating($rating);
		$r->setText(mb_substr($text, 0, 2000)); // rendered escaped — never HTML
		$r->setStatus('pending');
		$r->setCreatedAt(new \DateTimeImmutable());
		try {
			$this->reviews->insert($r);
		} catch (\OCP\DB\Exception $e) {
			// only the line_id unique index may claim a duplicate — a
			// transient DB error lying "already reviewed" hides the fault
			if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			return $this->fail(409, 'CONFLICT', 'You already reviewed this booking.');
		}
		return $this->ok(['status' => 'pending'], 201);
	}
}

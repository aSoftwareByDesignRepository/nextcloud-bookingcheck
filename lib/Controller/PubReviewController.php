<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\OrderLineMapper;
use OCA\BookingCheck\Db\Review;
use OCA\BookingCheck\Db\ReviewMapper;
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
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function store(): JSONResponse
	{
		$lineId = $this->strictId($this->request->getParam('line_id'));
		$rating = $this->strictId($this->request->getParam('rating'));
		$text = trim((string)$this->request->getParam('text', ''));
		if ($lineId === null || $rating === null || $rating < 1 || $rating > 5) {
			return $this->fail(400, 'VALIDATION', 'Pick a rating 1–5.');
		}
		// entitlement: the line must belong to a PAID order (verified purchase)
		$line = $this->lines->find($lineId);
		if ($line === null) {
			return $this->notFound();
		}
		$qb = \OCP\Server::get(\OCP\IDBConnection::class)->getQueryBuilder();
		$qb->select('status')->from('bc_order')
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$line->getOrderId())));
		if ($qb->executeQuery()->fetchOne() !== 'paid') {
			return $this->notFound();
		}
		$r = new Review();
		$r->setLineId($lineId);
		$r->setRating($rating);
		$r->setText(mb_substr($text, 0, 2000)); // rendered escaped — never HTML
		$r->setStatus('pending');
		$r->setCreatedAt(new \DateTimeImmutable());
		try {
			$this->reviews->insert($r);
		} catch (\Throwable) {
			return $this->fail(409, 'CONFLICT', 'You already reviewed this booking.');
		}
		return $this->ok(['status' => 'pending'], 201);
	}
}

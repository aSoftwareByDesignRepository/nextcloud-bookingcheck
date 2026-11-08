<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\ReviewMapper;
use OCA\BookingCheck\Exception\NotFoundException;
use OCA\BookingCheck\Exception\ValidationException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

class ReviewController extends Controller
{
	// unique-conflict:ok — moderate() only updates status/published_at on an existing review; bc_rev_line_uq (line_id) is written at insert time by PubReviewController, which carries the REASON_UNIQUE catch.
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ReviewMapper $reviews,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse
	{
		return new TemplateResponse($this->appName, 'admin/reviews', [
			'navId' => 'reviews',
			'reviews' => array_map(fn ($r) => $r->jsonSerialize(), $this->reviews->findAll()),
		]);
	}

	#[NoAdminRequired]
	public function moderate(int $id): JSONResponse
	{
		$r = $this->reviews->find($id);
		if ($r === null) {
			throw new NotFoundException();
		}
		$to = (string)$this->request->getParam('to', '');
		if (!in_array($to, ['published', 'hidden'], true)) {
			throw new ValidationException(['to' => 'Illegal moderation state.']);
		}
		$r->setStatus($to);
		if ($to === 'published') {
			$r->setPublishedAt(new \DateTimeImmutable());
		}
		$this->reviews->update($r);
		return $this->ok(['status' => $to]);
	}
}

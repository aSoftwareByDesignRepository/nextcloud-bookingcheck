<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\WaitlistEntryMapper;
use OCA\BookingCheck\Exception\NotFoundException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

class WaitlistController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly WaitlistEntryMapper $waitlist,
		private readonly \OCA\BookingCheck\Service\WaitlistService $service,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse
	{
		return new TemplateResponse($this->appName, 'admin/waitlist', [
			'navId' => 'waitlist',
			// whitelist — jsonSerialize ships claimTokenHash (a live claim
			// credential) to the template for fields it never reads
			'entries' => array_map(
				fn (\OCA\BookingCheck\Db\WaitlistEntry $w) => [
					'id' => (int)$w->getId(),
					'targetType' => $w->getTargetType(),
					'targetId' => (int)$w->getTargetId(),
					'email' => $w->getEmail(),
					'locale' => $w->getLocale(),
					'state' => $w->getState(),
					'offerExpiresAt' => $w->getOfferExpiresAt()?->format(\DateTimeInterface::ATOM),
					'createdAt' => $w->getCreatedAt()?->format(\DateTimeInterface::ATOM),
				],
				$this->waitlist->findAll()),
		]);
	}

	#[NoAdminRequired]
	public function remove(int $id): JSONResponse
	{
		// offered entries hold a reserved seat — the service releases it
		// (and offers it onward) inside the waitlist lock hierarchy
		if (!$this->service->decline($id)) {
			throw new NotFoundException();
		}
		return $this->ok();
	}
}

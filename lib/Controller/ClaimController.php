<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\WaitlistEntryMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\IRequest;

/** Waitlist claim link — consumes an offer hold into a real checkout. */
class ClaimController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly WaitlistEntryMapper $waitlist,
		private readonly \OCA\BookingCheck\Service\TokenService $tokens,
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
		return new PublicTemplateResponse($this->appName, 'public/claim', [
			'target_type' => $row->getTargetType(),
			'target_id' => (int)$row->getTargetId(),
			'email' => $row->getEmail(),
			'token' => $token,
		]);
	}
}

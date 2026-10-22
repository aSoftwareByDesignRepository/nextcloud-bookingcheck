<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Exception\ValidationException;
use OCA\BookingCheck\Service\WaitlistService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class PubWaitlistController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly WaitlistService $waitlist,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Join — uniform 202 for every business outcome (joined, already
	 * listed, target not joinable) so the response never oracles offering
	 * existence or waitlist state. Malformed *input shape* is different:
	 * a bad target_type/id or an invalid email is a client contract error,
	 * not an oracle — answering 400 there keeps a typo'd email from being
	 * fake-accepted while the buyer believes they are waitlisted.
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function join(): JSONResponse
	{
		$tt = (string)$this->request->getParam('target_type', '');
		$tid = $this->strictId($this->request->getParam('target_id'));
		$email = strtolower(trim(
			(string)$this->request->getParam('email', '')));
		if (!in_array($tt, ['event', 'slot', 'series'], true) || $tid === null) {
			throw new ValidationException(['target_id' => 'Invalid waitlist target.']);
		}
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			throw new ValidationException(['email' => 'Enter a valid email address.']);
		}
		$this->waitlist->join($tt, $tid, $email,
			substr((string)$this->request->getParam('locale', 'en'), 0, 10));
		return $this->ok(['joined' => true], 202);
	}
}

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

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

	/** Join — uniform 202; dedupe silent; per-email caps in EmailService. */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function join(): JSONResponse
	{
		$tt = (string)$this->request->getParam('target_type', '');
		$tid = $this->strictId($this->request->getParam('target_id'));
		$email = (string)$this->request->getParam('email', '');
		if (in_array($tt, ['event', 'slot', 'series'], true) && $tid !== null) {
			$this->waitlist->join($tt, $tid, $email,
				substr((string)$this->request->getParam('locale', 'en'), 0, 10));
		}
		return $this->ok(['joined' => true], 202);
	}
}

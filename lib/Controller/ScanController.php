<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Service\TicketService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUserSession;

/** Web door scanner — admin session only, camera-based check-in. */
class ScanController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly TicketService $tickets,
		private readonly IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse
	{
		return new TemplateResponse($this->appName, 'admin/scan', ['navId' => 'scan']);
	}

	#[NoAdminRequired]
	public function checkIn(): JSONResponse
	{
		$qr = (string)$this->request->getParam('qr', '');
		if (!preg_match('/^BCK1:\d+:([A-Za-z0-9_-]{32,64})$/', $qr, $m)) {
			return $this->fail(400, 'BAD_QR', 'Not a BookingCheck ticket.');
		}
		$result = $this->tickets->checkInByToken($m[1],
			$this->userSession->getUser()?->getUID() ?? 'scan');
		if (!$result['ok']) {
			return $this->fail(404, 'NOT_FOUND', 'Unknown ticket.');
		}
		return $this->ok([
			'checked_in' => true,
			'already' => $result['already'],
			'attendee' => $result['ticket']->getAttendeeName(),
		]);
	}

	#[NoAdminRequired]
	public function lookup(): JSONResponse
	{
		return $this->checkIn();
	}
}

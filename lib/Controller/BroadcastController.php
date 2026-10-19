<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\BroadcastMapper;
use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Exception\NotFoundException;
use OCA\BookingCheck\Service\AuditService;
use OCA\BookingCheck\Service\BroadcastService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/** Attendee broadcasts — seller mails all paid buyers of an offering. */
class BroadcastController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly BroadcastMapper $broadcasts,
		private readonly OfferingMapper $offerings,
		private readonly BroadcastService $service,
		private readonly AuditService $audit,
		private readonly IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	/** GET /api/offerings/{id}/broadcasts — send history. */
	#[NoAdminRequired]
	public function index(int $id): JSONResponse
	{
		if ($this->offerings->find($id) === null) {
			throw new NotFoundException();
		}
		$all = array_filter($this->broadcasts->findAll(),
			fn ($b) => (int)$b->getOfferingId() === $id);
		return $this->ok(['broadcasts' => array_values(array_map(
			fn ($b) => $b->jsonSerialize(), $all))]);
	}

	/** POST /api/offerings/{id}/broadcasts {subject, body, target_type?, target_id?} */
	#[NoAdminRequired]
	public function send(int $id): JSONResponse
	{
		if ($this->offerings->find($id) === null) {
			throw new NotFoundException();
		}
		$ttype = trim((string)$this->request->getParam('target_type', ''));
		$tid = $this->strictId($this->request->getParam('target_id'));
		$occurrence = ($ttype !== '' && $tid !== null) ? [$ttype, $tid] : null;
		$uid = $this->userSession->getUser()?->getUID() ?? 'system';

		$r = $this->service->send($id, $occurrence,
			(string)$this->request->getParam('subject', ''),
			(string)$this->request->getParam('body', ''),
			$uid);
		$this->audit->log(null, $id, $uid, 'broadcast.sent',
			null, (string)$r['broadcastId'] . ':' . $r['recipients']);
		return $this->ok($r, 201);
	}
}

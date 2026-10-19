<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Exception\UnauthorizedException;
use OCA\BookingCheck\Middleware\AppAccessMiddleware;
use OCA\BookingCheck\Db\OfferingMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * OCS surface for sibling/machine consumers.
 *
 * OCS paths live under /ocs/v2.php — outside AppAccessMiddleware's
 * /apps/ prefix — so the seller-role check MUST live here, else every
 * authenticated user could enumerate draft offerings and internals.
 */
class OcsApiController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly OfferingMapper $offerings,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function offerings(): JSONResponse
	{
		$this->requireSellerRole();
		return new JSONResponse(array_map(
			fn ($o) => $o->jsonSerialize(), $this->offerings->findAll()));
	}

	#[NoAdminRequired]
	public function offeringsOcs(): OCSResponse
	{
		$this->requireSellerRole();
		return new OCSResponse(array_map(
			fn ($o) => $o->jsonSerialize(), $this->offerings->findAll()));
	}

	private function requireSellerRole(): void
	{
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null
			|| (!$this->groupManager->isAdmin($uid)
				&& !$this->groupManager->isInGroup($uid, AppAccessMiddleware::ADMIN_GROUP))) {
			throw new UnauthorizedException();
		}
	}
}

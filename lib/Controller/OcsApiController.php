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
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * OCS surface for sibling/machine consumers.
 *
 * OCS paths live under /ocs/v2.php — outside AppAccessMiddleware's
 * /apps/ prefix — so the seller-role check MUST live here, else every
 * authenticated user could enumerate draft offerings and internals.
 * OCSController wraps DataResponse into the OCS envelope — there is no
 * public OCSResponse class; returning anything else would fatal.
 */
class OcsApiController extends OCSController
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly OfferingMapper $offerings,
		private readonly IUserSession $userSession,
		private readonly \OCA\BookingCheck\Service\AccessControlService $accessControl,
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
	public function offeringsOcs(): DataResponse
	{
		try {
			$this->requireSellerRole();
		} catch (UnauthorizedException) {
			throw new OCSForbiddenException(\OCA\BookingCheck\Support\ErrorText::t(
				\OCA\BookingCheck\Support\ErrorText::l10nFor($this->request),
				'Seller role required'));
		}
		return new DataResponse(array_map(
			fn ($o) => $o->jsonSerialize(), $this->offerings->findAll()));
	}

	private function requireSellerRole(): void
	{
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null || !$this->accessControl->canUseApp($uid)) {
			throw new UnauthorizedException();
		}
	}
}

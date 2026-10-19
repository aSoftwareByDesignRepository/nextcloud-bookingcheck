<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\OfferingMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSResponse;
use OCP\IRequest;

class OcsApiController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly OfferingMapper $offerings,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function offerings(): JSONResponse
	{
		return new JSONResponse(array_map(
			fn ($o) => $o->jsonSerialize(), $this->offerings->findAll()));
	}

	#[NoAdminRequired]
	public function offeringsOcs(): OCSResponse
	{
		return new OCSResponse(array_map(
			fn ($o) => $o->jsonSerialize(), $this->offerings->findAll()));
	}
}

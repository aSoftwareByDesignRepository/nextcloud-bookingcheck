<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Middleware;

use OCA\BookingCheck\AppInfo\Application;
use OCA\BookingCheck\Db\CompanionTokenMapper;
use OCA\BookingCheck\Service\TokenService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Middleware;
use OCP\IRequest;

/**
 * Companion-app authentication (bookingcheck-scan / bookingcheck-manager).
 *
 * Auth = `Authorization: Bearer <device-token>` against bc_companion
 * (hashed at rest). Never accepts browser session cookies — the mobile
 * boundary is a separate trust domain with door|manager roles.
 */
class CompanionAuthMiddleware extends Middleware
{
	public function __construct(
		private readonly IRequest $request,
		private readonly CompanionTokenMapper $tokens,
		private readonly TokenService $tokenService,
	) {
	}

	public function beforeController(Controller $controller, string $methodName): void
	{
		if (!str_starts_with(get_class($controller), 'OCA\\BookingCheck\\Controller\\Capi')) {
			return;
		}
		$header = $this->request->getHeader('Authorization');
		if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
			throw new \OCA\BookingCheck\Exception\UnauthorizedException('bearer token required');
		}
		$hash = $this->tokenService->hash($m[1]);
		$row = $this->tokens->findByTokenHash($hash);
		if ($row === null || $row->getRevokedAt() !== null) {
			throw new \OCA\BookingCheck\Exception\UnauthorizedException('invalid device token');
		}
		$this->tokens->touch((int)$row->getId());
		// stash the authenticated device for controllers
		$controller->companionDevice = $row;
	}
}

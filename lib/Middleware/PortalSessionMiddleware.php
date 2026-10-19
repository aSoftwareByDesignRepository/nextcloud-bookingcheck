<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Middleware;

use OCA\BookingCheck\AppInfo\Application;
use OCA\BookingCheck\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Middleware;
use OCP\IRequest;

/**
 * Customer-portal session gate (§9.8).
 *
 * Every /pub/portal/* route except the session endpoints requires a valid
 * portal cookie. Entitlement itself is evaluated per-request by
 * EntitlementService — a session alone never grants materials.
 */
class PortalSessionMiddleware extends Middleware
{
	private const OPEN = [
		'/pub/portal',              // request page (GET)
		'/pub/api/portal/send',     // request OTP
		'/pub/api/portal/verify',   // verify code
		'/pub/portal/magic/',       // magic link lands outside session
		'/pub/portal/logout',       // logout tolerates dead sessions
	];

	public function __construct(
		private readonly IRequest $request,
		private readonly PortalSessionService $sessions,
	) {
	}

	public function beforeController(Controller $controller, string $methodName): void
	{
		if (!str_starts_with(get_class($controller), 'OCA\\BookingCheck\\')) {
			return;
		}
		$path = $this->request->getPathInfo();
		$portalPath = '/' . Application::APP_ID . '/pub/portal';
		if (!str_contains($path, $portalPath)) {
			return;
		}
		foreach (self::OPEN as $open) {
			if (str_contains($path, $open)) {
				return;
			}
		}
		$token = $this->request->getCookie('bck_portal');
		if ($token === null || $this->sessions->resolve($token) === null) {
			throw new \OCA\BookingCheck\Exception\UnauthorizedException('portal session required');
		}
	}
}

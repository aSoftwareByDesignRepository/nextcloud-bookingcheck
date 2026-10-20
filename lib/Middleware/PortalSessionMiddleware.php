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
 * Every /pub/portal/* route except the open endpoints requires a valid
 * portal cookie. Entitlement itself is evaluated per-request by
 * EntitlementService — a session alone never grants materials.
 *
 * Matching is segment-exact, the same bug class as the admin access
 * gate had: a `str_contains` exemption would have matched the whole
 * gated prefix ("/pub/portal" contains "/pub/portal"), silently turning
 * the middleware into dead code.
 */
class PortalSessionMiddleware extends Middleware
{
	/** Exact paths reachable without a session. */
	private const OPEN_EXACT = [
		'/pub/portal',        // request page (GET)
		'/pub/portal/logout', // logout tolerates dead sessions
	];

	/** Prefix paths reachable without a session. */
	private const OPEN_PREFIX = [
		'/pub/portal/magic/', // magic link lands outside session
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
		$path = ($this->request->getPathInfo() ?: '');
		$rest = $this->restPath($path);
		if ($rest === null) {
			return;
		}
		// gate only fires on the portal page tree (/pub/portal and below) —
		// /pub/api/portal/* OTP endpoints live outside it by design
		if (!($rest === '/pub/portal' || str_starts_with($rest, '/pub/portal/'))) {
			return;
		}
		if (in_array($rest, self::OPEN_EXACT, true)) {
			return;
		}
		foreach (self::OPEN_PREFIX as $prefix) {
			if (str_starts_with($rest, $prefix)) {
				return;
			}
		}
		$token = $this->request->getCookie('bck_portal');
		if ($token === null || $this->sessions->resolve($token) === null) {
			throw new \OCA\BookingCheck\Exception\UnauthorizedException('portal session required');
		}
	}

	/** Path after the app prefix ('/index.php/apps/x' or '/apps/x'), or
	 *  null when the request is not ours. */
	private function restPath(string $path): ?string
	{
		foreach (['/index.php/apps/' . Application::APP_ID, '/apps/' . Application::APP_ID] as $p) {
			if (str_starts_with($path, $p)) {
				return substr($path, strlen($p));
			}
		}
		return null;
	}
}

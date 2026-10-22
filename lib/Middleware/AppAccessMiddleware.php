<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Middleware;

use OCA\BookingCheck\AppInfo\Application;
use OCA\BookingCheck\Service\AccessControlService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Admin-side access gate.
 *
 * The logged-in seller UI (everything not /pub/, /psp/, /capi/, /scan)
 * requires {@see AccessControlService::canUseApp()} — app admins always,
 * allowlisted users/groups under restriction, everyone when the door is
 * Open. Members get through; everyone else meets the uniform 404 — a
 * restricted app is indistinguishable from an absent one
 * (restricted_door_status_oracle).
 */
class AppAccessMiddleware extends Middleware
{
	/** @deprecated Use AccessControlService::ADMIN_GROUP. */
	public const ADMIN_GROUP = AccessControlService::ADMIN_GROUP;

	public function __construct(
		private readonly IRequest $request,
		private readonly IUserSession $userSession,
		private readonly AccessControlService $accessControl,
	) {
	}

	public function beforeController(Controller $controller, string $methodName): void
	{
		if (!str_starts_with(get_class($controller), 'OCA\\BookingCheck\\')) {
			return;
		}
		$path = ($this->request->getPathInfo() ?: '');
		$appPrefix = null;
		foreach (['/index.php/apps/' . Application::APP_ID, '/apps/' . Application::APP_ID] as $p) {
			if (str_starts_with($path, $p)) {
				$appPrefix = $p;
				break;
			}
		}
		if ($appPrefix === null) {
			return;
		}
		// Every bookingcheck page gets the shared asset layer before any
		// early return — an unstyled page is as broken as an error.
		\OCA\BookingCheck\Support\AssetRegistrar::registerStyles();
		\OCA\BookingCheck\Support\AssetRegistrar::registerScripts();

		// Public + machine surfaces have their own guards — segment-exact
		// match so an admin route like /publish or /public-x can never
		// accidentally share the /pub exemption. /scan is exempt so
		// anonymous door tablets can reach the token-entry page — the
		// ScanController itself asserts admin-or-door-session on every
		// endpoint, and a door session unlocks nothing else. /needs-role
		// is NOT exempt: it renders only for entitled members who hit an
		// in-app role deny — a door-denied user must meet the uniform 404
		// even on the deny template (restricted_door_status_oracle).
		$rest = substr($path, strlen($appPrefix)); // e.g. '/pub/api/orders'
		foreach (['/pub', '/psp', '/capi', '/scan', '/api/scan'] as $open) {
			if ($rest === $open || str_starts_with($rest, $open . '/')) {
				return;
			}
		}
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new \OCA\BookingCheck\Exception\UnauthorizedException();
		}
		if ($this->accessControl->canUseApp($user->getUID())) {
			return;
		}
		throw new \OCA\BookingCheck\Exception\AppDoorDeniedException(
			'requires BookingCheck access');
	}
}

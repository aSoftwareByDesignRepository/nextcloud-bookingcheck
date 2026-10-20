<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Middleware;

use OCA\BookingCheck\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Middleware;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Admin-side access gate.
 *
 * The logged-in seller UI (everything not /pub/, /psp/, /capi/, /scan)
 * requires membership in the `bookingcheck_admin` group OR instance admin.
 * Members get through; everyone else lands on a friendly needs-role page.
 */
class AppAccessMiddleware extends Middleware
{
	public const ADMIN_GROUP = 'bookingcheck_admin';

	public function __construct(
		private readonly IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function beforeController(Controller $controller, string $methodName): void
	{
		if (!str_starts_with(get_class($controller), 'OCA\\BookingCheck\\')) {
			return;
		}
		$path = $this->request->getPathInfo();
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
		// endpoint, and a door session unlocks nothing else.
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
		$uid = $user->getUID();
		if ($this->groupManager->isAdmin($uid)
			|| $this->groupManager->isInGroup($uid, self::ADMIN_GROUP)) {
			return;
		}
		throw new \OCA\BookingCheck\Exception\UnauthorizedException('requires BookingCheck admin role');
	}
}

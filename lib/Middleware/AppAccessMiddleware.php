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
		if (!str_starts_with($path, '/apps/' . Application::APP_ID)
			&& !str_starts_with($path, '/index.php/apps/' . Application::APP_ID)) {
			return;
		}
		// Every bookingcheck page gets the shared asset layer before any
		// early return — an unstyled page is as broken as an error.
		\OCA\BookingCheck\Support\AssetRegistrar::registerStyles();
		\OCA\BookingCheck\Support\AssetRegistrar::registerScripts();

		// Public + machine surfaces have their own guards. /scan is NOT
		// public — it is the staff door page and stays behind the role.
		foreach (['/pub', '/psp', '/capi'] as $open) {
			if (str_contains($path, '/' . Application::APP_ID . $open)) {
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

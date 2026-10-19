<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Middleware;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Re-asserts the requesttoken on session-authenticated mutations.
 *
 * Public (/pub/, /psp/) and token-auth (/capi/) surfaces are exempt — they
 * never carry ambient session authority. For everything else (the seller
 * UI), a non-GET request by a logged-in user must present a valid NC CSRF
 * token, fail closed otherwise.
 */
class SessionCsrfMiddleware extends Middleware
{
	public function __construct(
		private readonly IRequest $request,
		private readonly IUserSession $userSession,
	) {
	}

	public function beforeController(Controller $controller, string $methodName): void
	{
		if (!str_starts_with(get_class($controller), 'OCA\\BookingCheck\\')) {
			return;
		}
		$path = $this->request->getPathInfo();
		if (str_contains($path, '/bookingcheck/pub/')
			|| str_contains($path, '/bookingcheck/psp/')
			|| str_contains($path, '/bookingcheck/capi/')) {
			return;
		}
		if ($this->request->getMethod() === 'GET' || $this->userSession->getUser() === null) {
			return;
		}
		$token = $this->request->getHeader('requesttoken');
		if ($token === '') {
			$token = (string)$this->request->getParam('requesttoken', '');
		}
		if (!$this->isValid($token)) {
			throw new \OCA\BookingCheck\Exception\UnauthorizedException('CSRF token missing or invalid');
		}
	}

	private function isValid(string $token): bool
	{
		if ($token === '') {
			return false;
		}
		try {
			$manager = \OCP\Server::get(\OC\Security\CSRF\CsrfTokenManager::class);
			return $manager->isTokenValid(new \OC\Security\CSRF\CsrfToken($token));
		} catch (\Throwable) {
			return false;
		}
	}
}

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Middleware;

use OCA\BookingCheck\Service\DoorSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;

/**
 * Re-asserts the requesttoken on session-authenticated mutations.
 *
 * Public (/pub/, /psp/) and token-auth (/capi/) surfaces are exempt — they
 * never carry ambient session authority. For everything else (the seller
 * UI), a non-GET request by a logged-in user must present a valid NC CSRF
 * token, fail closed otherwise.
 *
 * Guest door sessions (web scanner tablets, no NC login) carry ambient
 * cookie authority too — they get the same check. The session-mint
 * endpoint is exempt by construction: it is only reachable before the
 * session key exists, and the token it consumes is the credential.
 */
class SessionCsrfMiddleware extends Middleware
{
	public function __construct(
		private readonly IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ISession $session,
	) {
	}

	public function beforeController(Controller $controller, string $methodName): void
	{
		if (!str_starts_with(get_class($controller), 'OCA\\BookingCheck\\')) {
			return;
		}
		$rest = $this->restPath($this->request->getPathInfo());
		if ($rest === null) {
			return;
		}
		// token-authed / bearer surfaces carry no cookie authority —
		// PSP signature + CAPI bearer + public guest forms are checked elsewhere
		foreach (['/pub/', '/psp/', '/capi/'] as $prefix) {
			if (str_starts_with($rest, $prefix)) {
				return;
			}
		}
		if ($this->request->getMethod() === 'GET') {
			return;
		}
		if ($this->userSession->getUser() === null
			&& $this->session->get(DoorSessionService::SESSION_KEY) === null) {
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

	private function restPath(string $path): ?string
	{
		foreach (['/index.php/apps/bookingcheck', '/apps/bookingcheck'] as $base) { // urlgate:ok — PathInfo prefix match, not URL generation
			if (str_starts_with($path, $base)) {
				return substr($path, strlen($base));
			}
		}
		return null;
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

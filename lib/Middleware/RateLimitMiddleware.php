<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Middleware;

use OCA\BookingCheck\Exception\RateLimitException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;

/**
 * Throttle public write endpoints via Nextcloud's bruteforce throttler.
 * Per-IP; per-EMAIL send caps live in EmailService (rotating-IP bombing
 * can't be solved by IP throttles alone).
 */
class RateLimitMiddleware extends Middleware
{
	private const RULES = [
		'/pub/api/orders'          => ['action' => 'bck_checkout',   'sleep' => 250],
		'/pub/api/portal/send'     => ['action' => 'bck_otp_send',   'sleep' => 250],
		'/pub/api/portal/verify'   => ['action' => 'bck_otp_verify', 'sleep' => 400],
		'/pub/api/waitlist'        => ['action' => 'bck_waitlist',   'sleep' => 250],
		'/pub/api/reviews'         => ['action' => 'bck_review',     'sleep' => 250],
		'/pub/api/promo/validate'  => ['action' => 'bck_promo',      'sleep' => 200],
		'/pub/m/recover'           => ['action' => 'bck_recover',    'sleep' => 400],
	];

	public function __construct(
		private readonly IRequest $request,
		private readonly IThrottler $throttler,
	) {
	}

	public function beforeController(Controller $controller, string $methodName): void
	{
		if (!str_starts_with(get_class($controller), 'OCA\\BookingCheck\\')) {
			return;
		}
		$path = $this->request->getPathInfo();
		foreach (self::RULES as $frag => $rule) {
			if (str_contains($path, $frag)) {
				$ip = $this->request->getRemoteAddress() ?: 'unknown';
				try {
					// sleeps progressively, throws once the cap is reached
					$this->throttler->sleepDelayOrThrowOnMax($ip, $rule['action']);
				} catch (\OCP\AppFramework\Http\TooManyRequestsException|\Exception) {
					throw new RateLimitException();
				}
				// count this hit — repeat callers slow down then get refused
				$this->throttler->registerAttempt($rule['action'], $ip, ['route' => $frag]);
				return;
			}
		}
	}
}

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
	/**
	 * Each rule: every 'needles' fragment must appear in the path AND the
	 * request verb must be listed. Verb+anchor precision matters — a bare
	 * '/pay' or '/uploads' substring also matches authenticated seller
	 * routes and public GET downloads, registering bruteforce attempts
	 * against legitimate users (GET /pub/api/orders/{ref} is the payment-
	 * status poll — throttling it would 429 buyers waiting to confirm).
	 */
	private const RULES = [
		['needles' => ['/pub/api/orders'],         'verbs' => ['POST'], 'action' => 'bck_checkout',   'sleep' => 250],
		// cart mints N holds in one call — same blast radius as /orders
		['needles' => ['/pub/api/cart'],           'verbs' => ['POST'], 'action' => 'bck_checkout',   'sleep' => 400],
		['needles' => ['/pub/api/portal/send'],    'verbs' => ['POST'], 'action' => 'bck_otp_send',   'sleep' => 250],
		['needles' => ['/pub/api/portal/verify'],  'verbs' => ['POST'], 'action' => 'bck_otp_verify', 'sleep' => 400],
		['needles' => ['/pub/api/waitlist'],       'verbs' => ['POST'], 'action' => 'bck_waitlist',   'sleep' => 250],
		['needles' => ['/pub/api/reviews'],        'verbs' => ['POST'], 'action' => 'bck_review',     'sleep' => 250],
		// abuse reports are free DB rows + moderator attention
		['needles' => ['/pub/portal/abuse'],       'verbs' => ['POST'], 'action' => 'bck_abuse',      'sleep' => 400],
		['needles' => ['/pub/api/promo/validate'], 'verbs' => ['POST'], 'action' => 'bck_promo',      'sleep' => 200],
		['needles' => ['/pub/m/recover'],          'verbs' => ['POST'], 'action' => 'bck_recover',    'sleep' => 400],
		// claim accept transfers a held seat + mails the new manage token —
		// a valid token holder (or leaked token) must not spam it
		['needles' => ['/pub/claim/'],             'verbs' => ['POST'], 'action' => 'bck_claim',      'sleep' => 400],
		// door-session mint consumes a device token — the 256-bit space is
		// unguessable, but there is no reason to let anyone try fast
		['needles' => ['/scan/session'],           'verbs' => ['POST'], 'action' => 'bck_door_sess',  'sleep' => 400],
		// PSP session minting is a real-money call — a leaked manage token
		// or impatient buyer must not spawn unbounded checkout sessions
		['needles' => ['/pub/m/', '/pay'],         'verbs' => ['POST'], 'action' => 'bck_pay_now',    'sleep' => 400],
		['needles' => ['/pub/m/', '/cancel'],      'verbs' => ['POST'], 'action' => 'bck_pub_cancel', 'sleep' => 400],
		['needles' => ['/pub/m/', '/answers'],     'verbs' => ['POST'], 'action' => 'bck_intake',     'sleep' => 250],
		// upload churn: deleted rows free the quota, so without a cap a
		// paid-order holder could write unbounded blobs + DB rows
		['needles' => ['/pub/m/', '/uploads'],     'verbs' => ['POST', 'DELETE'], 'action' => 'bck_upload', 'sleep' => 400],
	];

	public function __construct(
		private readonly IRequest $request,
		private readonly IThrottler $throttler,
		private readonly \OCP\IConfig $config,
	) {
	}

	public function beforeController(Controller $controller, string $methodName): void
	{
		if (!str_starts_with(get_class($controller), 'OCA\\BookingCheck\\')) {
			return;
		}
		$path = ($this->request->getPathInfo() ?: '');
		$method = strtoupper((string)$this->request->getMethod());
		foreach (self::RULES as $rule) {
			if (!in_array($method, $rule['verbs'], true)) {
				continue;
			}
			$hit = true;
			foreach ($rule['needles'] as $needle) {
				if (!str_contains($path, $needle)) {
					$hit = false;
					break;
				}
			}
			if (!$hit) {
				continue;
			}
			$ip = $this->request->getRemoteAddress() ?: 'unknown';
			try {
				// sleepDelayOrThrowOnMax returns the delay in ms — the caller
				// must apply it (the throw on max is enforced inside).
				$delay = $this->throttler->sleepDelayOrThrowOnMax($ip, $rule['action']);
			} catch (\Exception) {
				throw new RateLimitException();
			}
			if ($delay > 0 && !$this->config->getSystemValueBool('auth.bruteforce.protection.testing')) {
				usleep($delay * 1000);
			}
			// count this hit — repeat callers slow down then get refused
			$this->throttler->registerAttempt($rule['action'], $ip, ['route' => $rule['needles'][0]]);
			return;
		}
	}
}

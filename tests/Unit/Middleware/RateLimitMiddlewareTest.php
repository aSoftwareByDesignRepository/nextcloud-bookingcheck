<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller {
	// concrete app controller — the middleware only acts on OCA\BookingCheck
	// classes, and generated mock class names do not carry the namespace
	class RateLimitProbeController extends \OCP\AppFramework\Controller
	{
		public function __construct(\OCP\IRequest $request)
		{
			parent::__construct('bookingcheck', $request);
		}
	}
}

namespace OCA\BookingCheck\Tests\Unit\Middleware {

use OCA\BookingCheck\Controller\RateLimitProbeController;
use OCA\BookingCheck\Middleware\RateLimitMiddleware;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * Substring path matching must not register bruteforce attempts against
 * legitimate traffic: GET routes (payment-status poll, file downloads)
 * and authenticated seller routes share fragments with public writes.
 */
final class RateLimitMiddlewareTest extends TestCase
{
	private IRequest&MockObject $request;
	private IThrottler&MockObject $throttler;
	private \OCP\IConfig&MockObject $config;
	private RateLimitMiddleware $mw;
	private string $path = '';
	private string $verb = 'GET';

	protected function setUp(): void
	{
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->throttler = $this->createMock(IThrottler::class);
		$this->config = $this->createMock(\OCP\IConfig::class);
		$this->mw = new RateLimitMiddleware($this->request, $this->throttler, $this->config);
		$this->request->method('getRemoteAddress')->willReturn('203.0.113.9');
		$this->request->method('getPathInfo')->willReturnCallback(fn () => $this->path);
		$this->request->method('getMethod')->willReturnCallback(fn () => $this->verb);
	}

	private function hit(string $method, string $path): void
	{
		$this->verb = $method;
		$this->path = $path;
		$this->mw->beforeController(new RateLimitProbeController($this->request), 'x');
	}

	public function testPublicWritesThrottled(): void
	{
		// each POST registers exactly one attempt on the right action
		$seen = [];
		$this->throttler->method('registerAttempt')
			->willReturnCallback(function ($action, $ip) use (&$seen) {
				$seen[] = $action;
			});
		$this->hit('POST', '/apps/bookingcheck/pub/api/orders');
		$this->hit('POST', '/apps/bookingcheck/pub/api/cart');
		$this->hit('POST', '/apps/bookingcheck/pub/m/tok123/pay');
		$this->hit('POST', '/apps/bookingcheck/pub/m/tok123/cancel');
		$this->hit('POST', '/apps/bookingcheck/pub/m/tok123/uploads');
		$this->hit('POST', '/apps/bookingcheck/pub/api/portal/send');
		$this->hit('POST', '/apps/bookingcheck/pub/claim/clm-abc');
		$this->hit('POST', '/apps/bookingcheck/api/scan/session');
		$this->hit('POST', '/apps/bookingcheck/pub/api/orders/REF42/cancel');
		self::assertSame(
			['bck_checkout', 'bck_checkout', 'bck_pay_now', 'bck_pub_cancel',
				'bck_upload', 'bck_otp_send', 'bck_claim', 'bck_door_sess', 'bck_checkout'],
			$seen
		);
	}

	public function testLegitimateReadsNeverRegisterAttempts(): void
	{
		$this->throttler->expects(self::never())->method('registerAttempt');
		$this->throttler->expects(self::never())->method('sleepDelayOrThrowOnMax');
		// payment-status poll — was throttled by the bare '/pub/api/orders' frag
		$this->hit('GET', '/apps/bookingcheck/pub/api/orders/REF42');
		// buyer-portal + manage download views — matched bare '/uploads'
		$this->hit('GET', '/apps/bookingcheck/pub/portal/uploads/7/thumb');
		$this->hit('GET', '/apps/bookingcheck/pub/m/tok123/uploads/7');
		// sandbox checkout redirect — matched bare '/pay'
		$this->hit('GET', '/apps/bookingcheck/pub/sandbox/pay/psp_123');
		// claim landing page — POST-only rule
		$this->hit('GET', '/apps/bookingcheck/pub/claim/clm-abc');
	}

	public function testReturnedDelayIsActuallyApplied(): void
	{
		// sleepDelayOrThrowOnMax only computes the delay — the middleware
		// must usleep() it or repeat attackers run at full speed.
		$this->throttler->method('sleepDelayOrThrowOnMax')->willReturn(120);
		$t0 = hrtime(true);
		$this->hit('POST', '/apps/bookingcheck/pub/api/orders');
		$elapsedMs = (hrtime(true) - $t0) / 1e6;
		self::assertGreaterThanOrEqual(110, $elapsedMs, 'returned delay was not slept');
	}

	public function testTestingFlagSkipsTheSleep(): void
	{
		$this->throttler->method('sleepDelayOrThrowOnMax')->willReturn(120);
		$this->config->method('getSystemValueBool')
			->with('auth.bruteforce.protection.testing')->willReturn(true);
		$t0 = hrtime(true);
		$this->hit('POST', '/apps/bookingcheck/pub/api/orders');
		$elapsedMs = (hrtime(true) - $t0) / 1e6;
		self::assertLessThan(100, $elapsedMs, 'testing flag should bypass the sleep');
	}

	public function testSellerRoutesNeverRegisterAttempts(): void
	{
		$this->throttler->expects(self::never())->method('registerAttempt');
		// authenticated surfaces sharing the fragments
		$this->hit('POST', '/apps/bookingcheck/api/orders/5/cancel');   // '/cancel'
		$this->hit('GET', '/apps/bookingcheck/api/uploads/5/file');     // '/uploads'
		$this->hit('DELETE', '/apps/bookingcheck/api/uploads/5');       // '/uploads'
		$this->hit('POST', '/apps/bookingcheck/api/offerings/3/materials');
	}
}

}

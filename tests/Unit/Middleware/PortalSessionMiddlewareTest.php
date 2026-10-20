<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit\Middleware;

use OCA\BookingCheck\Exception\UnauthorizedException;
use OCA\BookingCheck\Middleware\PortalSessionMiddleware;
use OCA\BookingCheck\Service\PortalSessionService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * The portal gate must be segment-exact: '/pub/portal' in an open-list
 * `str_contains` would exempt every gated page (it contains itself) —
 * the middleware was dead code until it matched on segments.
 */
final class PortalSessionMiddlewareTest extends TestCase
{
	private IRequest&MockObject $request;
	private PortalSessionService&MockObject $sessions;
	private PortalSessionMiddleware $mw;

	protected function setUp(): void
	{
		parent::setUp();
		require_once dirname(__DIR__, 2) . '/Support/FakeAdminController.php';
		$this->request = $this->createMock(IRequest::class);
		$this->sessions = $this->createMock(PortalSessionService::class);
		$this->mw = new PortalSessionMiddleware($this->request, $this->sessions);
	}

	private function at(string $path, ?string $cookie): void
	{
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getPathInfo')->willReturn($path);
		$this->request->method('getCookie')->willReturn($cookie);
		$this->mw = new PortalSessionMiddleware($this->request, $this->sessions);
	}

	private function callGate(): void
	{
		$this->mw->beforeController(
			new \OCA\BookingCheck\Controller\FakeAdminController(
				'bookingcheck', $this->request), 'show');
	}

	public function testPortalHomeNeedsSession(): void
	{
		$this->at('/apps/bookingcheck/pub/portal/home', null);
		$this->expectException(UnauthorizedException::class);
		$this->callGate();
	}

	public function testPortalHomeWithValidSessionPasses(): void
	{
		$this->at('/apps/bookingcheck/pub/portal/home', 'tok');
		$this->sessions->method('resolve')->with('tok')->willReturn('a@x.test');
		$this->callGate();
		self::assertTrue(true);
	}

	public function testPortalHomeWithDeadSessionRefused(): void
	{
		$this->at('/apps/bookingcheck/pub/portal/home', 'tok');
		$this->sessions->method('resolve')->willReturn(null);
		$this->expectException(UnauthorizedException::class);
		$this->callGate();
	}

	public function testOpenPathsReachable(): void
	{
		foreach (['/apps/bookingcheck/pub/portal',
			'/apps/bookingcheck/pub/portal/magic/abc123',
			'/apps/bookingcheck/pub/portal/logout'] as $p) {
			$this->at($p, null);
			$this->callGate();
		}
		self::assertTrue(true);
	}

	/** Segment-exact: a route containing the open prefix as a substring
	 *  must still hit the session check. */
	public function testLogoutLookalikeIsGated(): void
	{
		$this->at('/apps/bookingcheck/pub/portal/logout-anything', null);
		$this->expectException(UnauthorizedException::class);
		$this->callGate();
	}

	public function testNonPortalPathsUntouched(): void
	{
		foreach (['/apps/bookingcheck/pub/catalog', '/apps/bookingcheck/api/orders'] as $p) {
			$this->at($p, null);
			$this->callGate();
		}
		self::assertTrue(true);
	}

	public function testPortalOrderPathGated(): void
	{
		$this->at('/apps/bookingcheck/pub/portal/orders/9', null);
		$this->expectException(UnauthorizedException::class);
		$this->callGate();
	}
}

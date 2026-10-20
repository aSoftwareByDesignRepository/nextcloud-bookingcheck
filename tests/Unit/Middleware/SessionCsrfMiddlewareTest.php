<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit\Middleware;

use OCA\BookingCheck\Exception\UnauthorizedException;
use OCA\BookingCheck\Middleware\SessionCsrfMiddleware;
use OCA\BookingCheck\Service\DoorSessionService;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * The door session is ambient cookie authority exactly like an NC login —
 * a POST carrying it without a requesttoken must fail closed, and a guest
 * without either session is never bothered.
 */
final class SessionCsrfMiddlewareTest extends TestCase
{
	private IRequest&MockObject $request;
	private IUserSession&MockObject $users;
	private ISession&MockObject $session;
	private SessionCsrfMiddleware $mw;

	protected function setUp(): void
	{
		parent::setUp();
		require_once dirname(__DIR__, 2) . '/Support/FakeAdminController.php';
		$this->request = $this->createMock(IRequest::class);
		$this->users = $this->createMock(IUserSession::class);
		$this->session = $this->createMock(ISession::class);
		$this->mw = new SessionCsrfMiddleware($this->request, $this->users, $this->session);
	}

	private function at(string $path, string $method, ?string $uid, ?int $doorId): void
	{
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getPathInfo')->willReturn($path);
		$this->request->method('getMethod')->willReturn($method);
		$this->request->method('getHeader')->willReturn('');
		$this->request->method('getParam')->willReturn('');
		$this->users = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}
		$this->users->method('getUser')->willReturn($user);
		$this->session = $this->createMock(ISession::class);
		$this->session->method('get')
			->with(DoorSessionService::SESSION_KEY)->willReturn($doorId);
		$this->mw = new SessionCsrfMiddleware($this->request, $this->users, $this->session);
	}

	private function callGate(): void
	{
		$this->mw->beforeController(
			new \OCA\BookingCheck\Controller\FakeAdminController(
				'bookingcheck', $this->request), 'store');
	}

	public function testDoorSessionPostWithoutTokenIsRefused(): void
	{
		$this->at('/apps/bookingcheck/api/scan/check-in', 'POST', null, 3);
		$this->expectException(UnauthorizedException::class);
		$this->callGate();
	}

	public function testGuestWithoutAnySessionSkips(): void
	{
		// the session-mint endpoint must be reachable pre-session — the
		// device token it consumes IS the credential
		$this->at('/apps/bookingcheck/api/scan/session', 'POST', null, null);
		$this->callGate();
		self::assertTrue(true);
	}

	public function testGetRequestsAlwaysSkip(): void
	{
		$this->at('/apps/bookingcheck/api/orders', 'GET', null, 3);
		$this->callGate();
		self::assertTrue(true);
	}

	public function testPublicPathsSkip(): void
	{
		$this->at('/apps/bookingcheck/pub/api/orders', 'POST', 'seller', null);
		$this->callGate();
		self::assertTrue(true);
	}
}

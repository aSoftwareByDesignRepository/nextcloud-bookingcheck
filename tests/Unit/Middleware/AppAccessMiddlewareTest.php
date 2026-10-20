<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Tests\Unit\Middleware;

use OCA\BookingCheck\Exception\UnauthorizedException;
use OCA\BookingCheck\Middleware\AppAccessMiddleware;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The app-level access door: admin surface = instance admin OR
 * bookingcheck_admin group; /pub, /psp, /capi exempt. The exemption is
 * segment-exact — an admin route whose path merely contains "pub" as a
 * substring (e.g. /publish) must still hit the role gate.
 */
class AppAccessMiddlewareTest extends TestCase
{
	private IRequest&MockObject $request;
	private IUserSession&MockObject $session;
	private IGroupManager&MockObject $groups;
	private AppAccessMiddleware $mw;

	protected function setUp(): void
	{
		parent::setUp();
		// fixture lives under tests/ but declares OCA\BookingCheck\Controller
		// (the gate checks get_class prefix) — PSR-4 can't load it, so require
		require_once dirname(__DIR__, 2) . '/Support/FakeAdminController.php';
		$this->request = $this->createMock(IRequest::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->mw = new AppAccessMiddleware(
			$this->request, $this->session, $this->groups,
			$this->createMock(IURLGenerator::class));
	}

	private function at(string $path, ?string $uid, bool $admin = false, bool $inRole = false): void
	{
		// fresh mocks per scenario — re-stubbing keeps the first value
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getPathInfo')->willReturn($path);
		$this->session = $this->createMock(IUserSession::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}
		$this->session->method('getUser')->willReturn($user);
		$this->groups->method('isAdmin')->willReturn($admin);
		$this->groups->method('isInGroup')->willReturn($inRole);
		$this->mw = new AppAccessMiddleware(
			$this->request, $this->session, $this->groups,
			$this->createMock(IURLGenerator::class));
	}

	private function callGate(): void
	{
		// get_class() must start with OCA\BookingCheck\ — a PHPUnit mock's
		// generated class name would trip the foreign-namespace early return
		$this->mw->beforeController(
			new \OCA\BookingCheck\Controller\FakeAdminController(
				'bookingcheck', $this->request), 'show');
	}

	public function testPublicCheckoutExempt(): void
	{
		$this->at('/apps/bookingcheck/pub/api/orders', null);
		$this->callGate(); // no throw
		self::assertTrue(true);
	}

	public function testPubRootExempt(): void
	{
		$this->at('/apps/bookingcheck/pub', null);
		$this->callGate();
		self::assertTrue(true);
	}

	public function testPspAndCapiExempt(): void
	{
		foreach (['/apps/bookingcheck/psp/stripe/webhook', '/index.php/apps/bookingcheck/capi/v1/events'] as $p) {
			$this->at($p, null);
			$this->callGate();
		}
		self::assertTrue(true);
	}

	public function testLoggedOutAdminPathRejected(): void
	{
		$this->at('/apps/bookingcheck/api/orders/9', null);
		$this->expectException(UnauthorizedException::class);
		$this->callGate();
	}

	public function testPlainUserRejected(): void
	{
		$this->at('/apps/bookingcheck/api/orders/9', 'bob');
		$this->expectException(UnauthorizedException::class);
		$this->callGate();
	}

	public function testBookingcheckAdminPasses(): void
	{
		$this->at('/apps/bookingcheck/api/orders/9', 'seller', false, true);
		$this->callGate();
		self::assertTrue(true);
	}

	public function testInstanceAdminPasses(): void
	{
		$this->at('/apps/bookingcheck/api/orders/9', 'root', true, false);
		$this->callGate();
		self::assertTrue(true);
	}

	/** The substring trap: /publish is an ADMIN route, not /pub. */
	public function testPublishLikePathIsNotExempt(): void
	{
		$this->at('/apps/bookingcheck/publish', 'bob');
		$this->groups->method('isAdmin')->willReturn(false);
		$this->groups->method('isInGroup')->willReturn(false);
		$this->expectException(UnauthorizedException::class);
		$this->callGate();
	}

	/** /scan is middleware-exempt so anonymous door tablets reach the
	 *  token-entry page — the ScanController self-gates every endpoint
	 *  (door session or admin). A near-miss like /scanner must still hit
	 *  the role gate. */
	public function testScanPathsExemptButScannerLikeNot(): void
	{
		foreach (['/apps/bookingcheck/scan', '/apps/bookingcheck/api/scan/lookup'] as $p) {
			$this->at($p, null);
			$this->callGate();
		}
		$this->at('/apps/bookingcheck/scanner', 'bob');
		$this->expectException(UnauthorizedException::class);
		$this->callGate();
	}
}

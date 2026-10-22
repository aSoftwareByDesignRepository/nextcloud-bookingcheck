<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Tests\Unit\Middleware;

use OCA\BookingCheck\Exception\AppDoorDeniedException;
use OCA\BookingCheck\Exception\UnauthorizedException;
use OCA\BookingCheck\Middleware\AppAccessMiddleware;
use OCA\BookingCheck\Service\AccessControlService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The app-level access door's path matrix: /pub, /psp, /capi, /scan and
 * /api/scan are exempt; every other /apps/bookingcheck/* path delegates
 * to AccessControlService::canUseApp() — /needs-role included, since a
 * door-denied user must meet the uniform 404 even on the deny template
 * (restricted_door_status_oracle). The exemption is segment-exact — an
 * admin route whose path merely contains "pub" as a substring (e.g.
 * /publish) must still hit the role gate. The policy truth table itself
 * lives in AccessControlServiceTest; here the service is a stub.
 */
class AppAccessMiddlewareTest extends TestCase
{
	private IRequest&MockObject $request;
	private IUserSession&MockObject $session;
	private AccessControlService&MockObject $accessControl;
	private AppAccessMiddleware $mw;

	protected function setUp(): void
	{
		parent::setUp();
		// fixture lives under tests/ but declares OCA\BookingCheck\Controller
		// (the gate checks get_class prefix) — PSR-4 can't load it, so require
		require_once dirname(__DIR__, 2) . '/Support/FakeAdminController.php';
		$this->request = $this->createMock(IRequest::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->accessControl = $this->createMock(AccessControlService::class);
		$this->mw = new AppAccessMiddleware(
			$this->request, $this->session, $this->accessControl);
	}

	/**
	 * @param bool|null $canUse null = the stub must never be consulted
	 *                          (exempt path / anonymous short-circuit)
	 */
	private function at(string $path, ?string $uid, ?bool $canUse = false): void
	{
		// fresh mocks per scenario — re-stubbing keeps the first value
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getPathInfo')->willReturn($path);
		$this->session = $this->createMock(IUserSession::class);
		$this->accessControl = $this->createMock(AccessControlService::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}
		$this->session->method('getUser')->willReturn($user);
		if ($canUse === null) {
			$this->accessControl->expects(self::never())->method('canUseApp');
		} else {
			$this->accessControl->method('canUseApp')->willReturn($canUse);
		}
		$this->mw = new AppAccessMiddleware(
			$this->request, $this->session, $this->accessControl);
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
		$this->at('/apps/bookingcheck/pub/api/orders', null, null);
		$this->callGate(); // no throw, no policy call
		self::assertTrue(true);
	}

	public function testPubRootExempt(): void
	{
		$this->at('/apps/bookingcheck/pub', null, null);
		$this->callGate();
		self::assertTrue(true);
	}

	public function testPspAndCapiExempt(): void
	{
		foreach (['/apps/bookingcheck/psp/stripe/webhook', '/index.php/apps/bookingcheck/capi/v1/events'] as $p) {
			$this->at($p, null, null);
			$this->callGate();
		}
		self::assertTrue(true);
	}

	/**
	 * /needs-role is NOT exempt: a door-denied user must get the uniform
	 * 404 even on the deny template — an exempt 403 landing would still
	 * disclose "installed but you are excluded". Entitled members pass
	 * the gate and render it for in-app role denies.
	 */
	public function testNeedsRoleHitsTheGate(): void
	{
		$this->at('/apps/bookingcheck/needs-role', 'bob', false);
		$this->expectException(AppDoorDeniedException::class);
		$this->callGate();
	}

	public function testNeedsRolePassesForEntitledMember(): void
	{
		$this->at('/apps/bookingcheck/needs-role', 'member', true);
		$this->callGate();
		self::assertTrue(true);
	}

	public function testLoggedOutAdminPathRejected(): void
	{
		$this->at('/apps/bookingcheck/api/orders/9', null, null);
		$this->expectException(UnauthorizedException::class);
		$this->callGate();
	}

	public function testDeniedUserRejected(): void
	{
		$this->at('/apps/bookingcheck/api/orders/9', 'bob', false);
		$this->expectException(AppDoorDeniedException::class);
		$this->callGate();
	}

	public function testAllowedUserPasses(): void
	{
		$this->at('/apps/bookingcheck/api/orders/9', 'seller', true);
		$this->callGate();
		self::assertTrue(true);
	}

	/** The substring trap: /publish is an ADMIN route, not /pub. */
	public function testPublishLikePathIsNotExempt(): void
	{
		$this->at('/apps/bookingcheck/publish', 'bob', false);
		$this->expectException(AppDoorDeniedException::class);
		$this->callGate();
	}

	/** /scan is middleware-exempt so anonymous door tablets reach the
	 *  token-entry page — the ScanController self-gates every endpoint
	 *  (door session or app admin). A near-miss like /scanner must still
	 *  hit the role gate. */
	public function testScanPathsExemptButScannerLikeNot(): void
	{
		foreach (['/apps/bookingcheck/scan', '/apps/bookingcheck/api/scan/lookup'] as $p) {
			$this->at($p, null, null);
			$this->callGate();
		}
		$this->at('/apps/bookingcheck/scanner', 'bob', false);
		$this->expectException(AppDoorDeniedException::class);
		$this->callGate();
	}

	/** The settings catalog is NOT exempt — an allowlisted non-admin
	 *  organizer passes the gate; the app-admin-only check lives in
	 *  SettingsController::requireConfigAdmin. */
	public function testSettingsPathHitsTheGate(): void
	{
		$this->at('/apps/bookingcheck/settings/access', 'organizer', true);
		$this->callGate();
		self::assertTrue(true);
	}
}

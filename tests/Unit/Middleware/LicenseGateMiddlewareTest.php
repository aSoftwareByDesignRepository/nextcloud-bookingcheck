<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Tests\Unit\Middleware;

use OCA\BookingCheck\Controller\CapiController;
use OCA\BookingCheck\Controller\ManageController;
use OCA\BookingCheck\Controller\PageController;
use OCA\BookingCheck\Controller\SettingsController;
use OCA\BookingCheck\Controller\VoucherController;
use OCA\BookingCheck\Exception\ProRequiredException;
use OCA\BookingCheck\Middleware\LicenseGateMiddleware;
use OCA\BookingCheck\Service\LicenseService;
use PHPUnit\Framework\TestCase;

class LicenseGateMiddlewareTest extends TestCase
{
	private function mw(bool $pro): LicenseGateMiddleware
	{
		// partial mock: hasFeature stubbed, requireFeature real (throws)
		$license = $this->getMockBuilder(LicenseService::class)
			->disableOriginalConstructor()
			->onlyMethods(['hasFeature'])->getMock();
		$license->method('hasFeature')->willReturn($pro);
		return new LicenseGateMiddleware($license);
	}

	public function testFreeTierBlocksWholeControllerGates(): void
	{
		$this->expectException(ProRequiredException::class);
		$this->mw(false)->beforeController(
			$this->createMock(VoucherController::class), 'redeem');
	}

	public function testFreeTierBlocksMethodScopedGate(): void
	{
		$this->expectException(ProRequiredException::class);
		$this->mw(false)->beforeController(
			$this->createMock(SettingsController::class), 'issueDevice');
	}

	public function testFreeTierAllowsUnmappedMethod(): void
	{
		$this->mw(false)->beforeController(
			$this->createMock(SettingsController::class), 'save');
		$this->addToAssertionCount(1); // no exception
	}

	public function testFreeTierAllowsUngatedController(): void
	{
		$this->mw(false)->beforeController(
			$this->createMock(PageController::class), 'index');
		$this->addToAssertionCount(1);
	}

	public function testCompanionGateAppliesToCapi(): void
	{
		$this->expectException(ProRequiredException::class);
		$this->mw(false)->beforeController(
			$this->createMock(CapiController::class), 'events');
	}

	public function testProTierPassesEveryGate(): void
	{
		$mw = $this->mw(true);
		$mw->beforeController($this->createMock(VoucherController::class), 'redeem');
		$mw->beforeController($this->createMock(CapiController::class), 'checkIn');
		$mw->beforeController($this->createMock(SettingsController::class), 'issueDevice');
		$this->addToAssertionCount(1);
	}
}

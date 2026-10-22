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

	public function testEmbedGateCoversIframeOnly(): void
	{
		$mw = $this->mw(false);
		$this->expectException(ProRequiredException::class);
		$mw->beforeController(
			$this->createMock(\OCA\BookingCheck\Controller\EmbedController::class), 'offering');
	}

	public function testEmbedSdkAssetIsUngated(): void
	{
		// sdk.js is a static bootstrap file — gating it adds nothing (the
		// data feed is gated separately) and only breaks the embed page's
		// own loader on free instances
		$this->mw(false)->beforeController(
			$this->createMock(\OCA\BookingCheck\Controller\EmbedController::class), 'sdk');
		$this->addToAssertionCount(1);
	}

	public function testEmbedJsonFeedSharesTheProBoundary(): void
	{
		// the SDK's data feed must not be a free path around the iframe gate
		$this->expectException(ProRequiredException::class);
		$this->mw(false)->beforeController(
			$this->createMock(\OCA\BookingCheck\Controller\PubCatalogController::class), 'offeringJson');
	}

	public function testPublicCatalogPagesStayFree(): void
	{
		$mw = $this->mw(false);
		$mw->beforeController(
			$this->createMock(\OCA\BookingCheck\Controller\PubCatalogController::class), 'index');
		$mw->beforeController(
			$this->createMock(\OCA\BookingCheck\Controller\PubCatalogController::class), 'offering');
		$this->addToAssertionCount(1);
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

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Tests\Unit\Service;

use OCA\BookingCheck\Db\CompanionTokenMapper;
use OCA\BookingCheck\Db\MailTplMapper;
use OCA\BookingCheck\Service\LicenseService;
use OCA\BookingCheck\Service\SettingsViewData;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use Test\TestCase;

/**
 * View-data contract: every key the settings templates read must be
 * shipped by SettingsViewData::get(). Regression-pin for the license
 * pack adapter — templates/settings/license.php reads $_['licenseStatus']
 * for validUntil/proSeats/customerId; shipping only 'licenseState' left
 * the pack rendering "Valid until: —", "N of 0 device slots" and an
 * empty Customer ID under a valid Pro key.
 */
final class SettingsViewDataTest extends TestCase
{
	private function viewData(array $licenseStatus): SettingsViewData
	{
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getAppValue')->willReturnCallback(
			static fn (string $k, string $d = '') => $d);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn(false);

		$license = $this->createMock(LicenseService::class);
		$license->method('status')->willReturn($licenseStatus);
		$license->method('hasFeature')->willReturn(false);

		$mailTpls = $this->createMock(MailTplMapper::class);
		$mailTpls->method('listAll')->willReturn([]);

		$companions = $this->createMock(CompanionTokenMapper::class);
		$companions->method('findAll')->willReturn([]);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $s, $p = []) => $s);

		return new SettingsViewData(
			$appConfig,
			null,
			$appManager,
			$this->createMock(IConfig::class),
			$license,
			$mailTpls,
			$companions,
			$l10n,
			$this->createMock(IURLGenerator::class),
		);
	}

	public function testLicenseStatusFullContractIsShipped(): void
	{
		$status = [
			'state' => 'pro',
			'validUntil' => '2027-12-31',
			'proSeats' => 100,
			'customerId' => 'test',
			'error' => '',
		];
		$out = $this->viewData($status)->get();

		self::assertArrayHasKey('licenseStatus', $out,
			'pack adapter reads $_[licenseStatus] — key must exist');
		self::assertSame($status, $out['licenseStatus'],
			'full status contract (validUntil/proSeats/customerId) must reach the template');
		self::assertSame('pro', $out['licenseState']);
	}

	public function testLicenseStatusShipsEmptyStateToo(): void
	{
		$status = [
			'state' => 'free',
			'validUntil' => null,
			'proSeats' => 0,
			'customerId' => '',
			'error' => '',
		];
		$out = $this->viewData($status)->get();

		self::assertArrayHasKey('licenseStatus', $out);
		self::assertSame($status, $out['licenseStatus']);
	}
}

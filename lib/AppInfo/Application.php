<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\AppInfo;

use OCP\Files\IRootFolder;
use OCA\BookingCheck\Middleware\AppAccessMiddleware;
use OCA\BookingCheck\Middleware\CompanionAuthMiddleware;
use OCA\BookingCheck\Middleware\PortalSessionMiddleware;
use OCA\BookingCheck\Middleware\PublicErrorMiddleware;
use OCA\BookingCheck\Middleware\RateLimitMiddleware;
use OCA\BookingCheck\Middleware\SessionCsrfMiddleware;
use OCA\BookingCheck\Repair\BackupBeforeUpdate;
use OCA\BookingCheck\Repair\EnsureBookingCheckSchema;
use OCA\BookingCheck\Repair\UninstallDropTables;
use OCA\BookingCheck\Service\UpgradeBackupService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

// Bundled splitbrain/phpQRCode lives under lib/Vendor. Nextcloud only autoloads
// OCA\* classes, so map the vendored prefix here (same pattern as tourcheck).
spl_autoload_register(static function (string $class): void {
	$prefix = 'splitbrain\\phpQRCode\\';
	if (str_starts_with($class, $prefix)) {
		$file = __DIR__ . '/../Vendor/splitbrain/phpQRCode/'
			. str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($file)) {
			require_once $file;
		}
	}
}, true, false);

class Application extends App implements IBootstrap
{
	public const APP_ID = 'bookingcheck';

	public function __construct()
	{
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void
	{
		// ---------------------------------------------------------------
		// Repair steps — EVERY info.xml <step> FQCN is registered here with
		// all required constructor args (nextcloud-repair-step-di skill).
		// ---------------------------------------------------------------
		$context->registerService(EnsureBookingCheckSchema::class, function ($c): EnsureBookingCheckSchema {
			return new EnsureBookingCheckSchema(
				$c->get(\OCP\IDBConnection::class),
				$c->get(\OCP\IConfig::class),
			);
		});
		$context->registerService(UpgradeBackupService::class, function ($c): UpgradeBackupService {
			return new UpgradeBackupService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(\OCP\IConfig::class),
				$c->get(\OCP\Files\IRootFolder::class),
				$c->get(\OCP\App\IAppManager::class),
				$c->get(\OCP\Lock\ILockingProvider::class),
				$c->get(\Psr\Log\LoggerInterface::class),
			);
		});
		$context->registerService(BackupBeforeUpdate::class, function ($c): BackupBeforeUpdate {
			return new BackupBeforeUpdate(
				$c->get(UpgradeBackupService::class),
			);
		});
		$context->registerService(UninstallDropTables::class, function ($c): UninstallDropTables {
			return new UninstallDropTables(
				$c->get(\OCP\IDBConnection::class),
				$c->get(\OCP\IConfig::class),
				$c->get(\OCP\Files\IRootFolder::class),
			);
		});

		// ---------------------------------------------------------------
		// Middleware — registration order matters: afterException iterates
		// in REVERSE order, so the generic envelope registers FIRST and is
		// consulted LAST; specific middlewares handle their routes first.
		// ---------------------------------------------------------------
		$context->registerMiddleware(PublicErrorMiddleware::class);
		$context->registerMiddleware(AppAccessMiddleware::class);
		$context->registerMiddleware(\OCA\BookingCheck\Middleware\LicenseGateMiddleware::class);
		$context->registerMiddleware(PortalSessionMiddleware::class);
		$context->registerMiddleware(CompanionAuthMiddleware::class);
		$context->registerMiddleware(RateLimitMiddleware::class);
		$context->registerMiddleware(SessionCsrfMiddleware::class);

		$context->registerNotifierService(\OCA\BookingCheck\Notification\Notifier::class);
		$context->registerDashboardWidget(\OCA\BookingCheck\Dashboard\BookingsWidget::class);
	}

	public function boot(IBootContext $context): void
	{
		$app = $context->getAppContainer();
		\OCA\BookingCheck\Support\NavGate::register(
			$app,
			'bookingcheck.page.index',
			'app.svg',
			'BookingCheck',
			10,
			static function (string $uid) use ($app): bool {
				return $app->get(\OCA\BookingCheck\Service\AccessControlService::class)->canUseApp($uid);
			},
		);
	}
}

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Middleware;

use OCA\BookingCheck\Exception\ProRequiredException;
use OCA\BookingCheck\Service\LicenseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Middleware;

/**
 * Server-side Pro gate — the ONLY enforcement point that cannot be
 * missed by a route addition inside an already-mapped controller.
 *
 * Map value forms:
 *   'feature'             → the whole controller is Pro-gated
 *   ['m1' => 'f', 'm2' => 'f']  → only the named actions are gated
 *
 * Deep paths that can be reached from FREE controllers (checkout with
 * a voucher code, seat release → waitlist offer, order transitions →
 * outbound webhooks / CRM sync) get their own service-level checks —
 * this middleware is the outer wall, not the only wall.
 */
class LicenseGateMiddleware extends Middleware
{
	private const GATES = [
		\OCA\BookingCheck\Controller\VoucherController::class    => 'vouchers',
		\OCA\BookingCheck\Controller\PromoController::class      => 'promos',
		\OCA\BookingCheck\Controller\PubWaitlistController::class => 'waitlist',
		\OCA\BookingCheck\Controller\WaitlistController::class   => 'waitlist',
		\OCA\BookingCheck\Controller\ClaimController::class      => 'waitlist',
		\OCA\BookingCheck\Controller\PortalController::class     => 'portal',
		\OCA\BookingCheck\Controller\EmbedController::class      => 'embed',
		\OCA\BookingCheck\Controller\FeedController::class       => 'directory_feed',
		\OCA\BookingCheck\Controller\WebhookController::class    => 'outbound_webhooks',
		\OCA\BookingCheck\Controller\MaterialController::class   => 'materials',
		\OCA\BookingCheck\Controller\PubReviewController::class  => 'reviews',
		\OCA\BookingCheck\Controller\ReviewController::class     => 'reviews',
		\OCA\BookingCheck\Controller\ResourceController::class   => 'resources',
		\OCA\BookingCheck\Controller\IntakeController::class     => 'intake_questions',
		\OCA\BookingCheck\Controller\CapiController::class       => 'companion',
		\OCA\BookingCheck\Controller\ReportController::class     => 'reports_csv',
		\OCA\BookingCheck\Controller\UploadController::class     => 'uploads',
		\OCA\BookingCheck\Controller\BroadcastController::class  => 'broadcasts',
		\OCA\BookingCheck\Controller\SettingsController::class   => [
			'devices' => 'companion', 'issueDevice' => 'companion', 'revokeDevice' => 'companion',
		],
		\OCA\BookingCheck\Controller\ManageController::class     => [
			'answers' => 'intake_questions',
			'uploadFile' => 'uploads', 'uploadDownload' => 'uploads',
			'uploadDelete' => 'uploads',
		],
	];

	public function __construct(
		private readonly LicenseService $license,
	) {
	}

	public function beforeController(Controller $controller, string $methodName): void
	{
		// instanceof (not get_class): DI proxies/mocks subclass the controller.
		$gate = null;
		foreach (self::GATES as $class => $g) {
			if ($controller instanceof $class) {
				$gate = $g;
				break;
			}
		}
		if ($gate === null) {
			return;
		}
		if (is_string($gate)) {
			$this->license->requireFeature($gate);
			return;
		}
		if (isset($gate[$methodName])) {
			$this->license->requireFeature($gate[$methodName]);
		}
	}
}

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\BackgroundJob;

use OCA\BookingCheck\Service\AuditService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/** Daily tamper check — a broken chain row id goes to the log + admin. */
class AuditChainVerifyJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly AuditService $audit,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
	}

	protected function run($argument): void
	{
		$brokenAt = $this->audit->verifyChain();
		if ($brokenAt !== 0) {
			$this->logger->critical('BookingCheck audit chain broken at row ' . $brokenAt
				. ' — possible tampering. Investigate immediately.');
		}
	}
}

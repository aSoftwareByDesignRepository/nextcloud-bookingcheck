<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Tests\Unit\BackgroundJob;

use OCA\BookingCheck\BackgroundJob\AttendeeReminderJob;
use OCA\BookingCheck\BackgroundJob\AuditChainVerifyJob;
use OCA\BookingCheck\BackgroundJob\CrmSyncJob;
use OCA\BookingCheck\BackgroundJob\EmailQueueJob;
use OCA\BookingCheck\BackgroundJob\EolJob;
use OCA\BookingCheck\BackgroundJob\OrderExpiryJob;
use OCA\BookingCheck\BackgroundJob\OutboundWebhookJob;
use OCA\BookingCheck\BackgroundJob\RetentionPruneJob;
use OCA\BookingCheck\BackgroundJob\SlotMaterializeJob;
use OCA\BookingCheck\BackgroundJob\TransferApplyJob;
use OCA\BookingCheck\BackgroundJob\WaitlistAllocationJob;
use OCA\BookingCheck\BackgroundJob\WaitlistExpiryJob;
use OCA\BookingCheck\Service\EolService;
use OCA\BookingCheck\Service\SlotMaterializer;
use OCA\BookingCheck\Service\UpgradeBackupService;
use OCA\BookingCheck\Service\WaitlistService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Contract proof for the upgrade-backup coordination contract:
 * UpgradeBackupService::restore() holds LOCK_KEY exclusively — every
 * BackgroundJob that scans/writes app tables must take the SHARED lock,
 * skip on LockedException without doing work, and always release.
 * (restore_lock_contract_half_wired class — bkc r8 fix, ported from
 * customercheck/azc sibling test.)
 */
final class UpgradeBackupSharedLockTest extends TestCase
{
	/** Every app BackgroundJob — the whole family is wired into the contract. */
	private const JOB_CLASSES = [
		AttendeeReminderJob::class,
		AuditChainVerifyJob::class,
		CrmSyncJob::class,
		EmailQueueJob::class,
		EolJob::class,
		OrderExpiryJob::class,
		OutboundWebhookJob::class,
		RetentionPruneJob::class,
		SlotMaterializeJob::class,
		TransferApplyJob::class,
		WaitlistAllocationJob::class,
		WaitlistExpiryJob::class,
	];

	/**
	 * Static contract: every job class (a) declares an ILockingProvider
	 * constructor dependency and (b) its source acquires the shared lock on
	 * UpgradeBackupService::LOCK_KEY and releases it in a finally.
	 */
	public function testEveryJobIsWiredIntoSharedLockContract(): void
	{
		foreach (self::JOB_CLASSES as $class) {
			$ref = new \ReflectionClass($class);
			$ctor = $ref->getConstructor();
			$this->assertNotNull($ctor, $class);
			$hasLockDep = false;
			foreach ($ctor->getParameters() as $p) {
				$t = $p->getType();
				if ($t instanceof \ReflectionNamedType && $t->getName() === ILockingProvider::class) {
					$hasLockDep = true;
				}
			}
			$this->assertTrue($hasLockDep, "$class constructor lacks an ILockingProvider dependency");

			$file = $ref->getFileName();
			$this->assertNotFalse($file, $class);
			$src = (string)file_get_contents($file);
			$this->assertStringContainsString('UpgradeBackupService::LOCK_KEY', $src, $class);
			$this->assertStringContainsString('ILockingProvider::LOCK_SHARED', $src, $class);
			$this->assertStringContainsString('releaseLock(UpgradeBackupService::LOCK_KEY', $src, $class);
			$this->assertStringContainsString('} finally {', $src, $class);
		}
		// Sanity: the contract really does cover the whole BackgroundJob dir —
		// no silently missed class when a twelfth job lands.
		$dir = dirname((string)(new \ReflectionClass(self::JOB_CLASSES[0]))->getFileName());
		$onDisk = [];
		foreach (glob($dir . '/*Job.php') ?: [] as $f) {
			$onDisk[] = 'OCA\\BookingCheck\\BackgroundJob\\' . basename($f, '.php');
		}
		sort($onDisk);
		$declared = self::JOB_CLASSES;
		sort($declared);
		$this->assertSame($onDisk, $declared, 'JOB_CLASSES must enumerate every *Job.php in lib/BackgroundJob');
	}

	private function invokeRun(object $job, $argument = null): void
	{
		$m = new \ReflectionMethod($job, 'run');
		$m->invoke($job, $argument);
	}

	private function lockedProvider(): ILockingProvider
	{
		$locking = $this->createMock(ILockingProvider::class);
		$locking->method('acquireLock')
			->with(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED)
			->willThrowException(new LockedException('restore in progress'));
		return $locking;
	}

	public function testLockedOutJobSkipsWithoutTouchingAppTables(): void
	{
		// A restore holds the exclusive backup lock — the EOL sweep must
		// skip, not write state rows into tables being truncated+reinserted.
		$eol = $this->createMock(EolService::class);
		$eol->expects($this->never())->method('processDue');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')
			->with($this->stringContains('skipping'));

		$time = $this->createMock(ITimeFactory::class);
		// Time must never be consulted — the skip happens before any work.
		$time->expects($this->never())->method('getTime');

		$job = new EolJob($time, $eol, $this->lockedProvider(), $logger);
		$this->invokeRun($job); // must not throw
	}

	public function testSharedLockIsReleasedWhenWorkThrows(): void
	{
		// acquire+release must bracket the work even when it blows up —
		// a leaked shared lock would starve the exclusive restore.
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->once())->method('acquireLock')
			->with(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED, $this->isType('string'));
		$locking->expects($this->once())->method('releaseLock')
			->with(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED);

		$waitlist = $this->createMock(WaitlistService::class);
		$waitlist->method('expireOffers')
			->willThrowException(new \RuntimeException('db gone'));

		$job = new WaitlistAllocationJob(
			$this->createMock(ITimeFactory::class),
			$waitlist,
			$locking,
			$this->createMock(LoggerInterface::class),
		);
		$this->expectException(\RuntimeException::class);
		$this->invokeRun($job);
	}

	public function testHappyPathAcquiresAndReleasesSharedLock(): void
	{
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->once())->method('acquireLock')
			->with(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED, $this->isType('string'));
		$locking->expects($this->once())->method('releaseLock')
			->with(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED);

		$materializer = $this->createMock(SlotMaterializer::class);
		$materializer->expects($this->once())->method('materializeAll');

		$job = new SlotMaterializeJob(
			$this->createMock(ITimeFactory::class),
			$materializer,
			$locking,
			$this->createMock(LoggerInterface::class),
		);
		$this->invokeRun($job);
	}
}

<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit\Service;

use OCA\BookingCheck\Db\SyncTaskMapper;
use OCA\BookingCheck\Service\CrmSyncService;
use OCA\BookingCheck\Service\LicenseService;
use OCA\BookingCheck\Service\SettingsService;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

final class CrmSyncServiceTest extends TestCase
{
	private IAppManager&MockObject $apps;
	private SettingsService&MockObject $settings;

	protected function setUp(): void
	{
		$this->apps = $this->createMock(IAppManager::class);
		$this->settings = $this->createMock(SettingsService::class);
	}

	private function proLicense(): LicenseService
	{
		$l = $this->createMock(LicenseService::class);
		$l->method('hasFeature')->willReturn(true);
		return $l;
	}

	private function svc(): CrmSyncService
	{
		return new CrmSyncService(
			$this->createMock(SyncTaskMapper::class),
			$this->settings, $this->apps,
			$this->createMock(LoggerInterface::class), $this->proLicense());
	}

	/** A service whose sibling boundary is replaced with a probe. */
	private function svcProbing(callable $probe): CrmSyncService
	{
		return new class(
			$this->createMock(SyncTaskMapper::class),
			$this->settings, $this->apps,
			$this->createMock(LoggerInterface::class),
			$this->proLicense(),
			$probe,
		) extends CrmSyncService {
			public function __construct($tasks, $settings, $apps, $logger, $license, private $probe)
			{
				parent::__construct($tasks, $settings, $apps, $logger, $license);
			}
			protected function callSibling(string $token, array $body): void
			{
				($this->probe)($token, $body);
			}
		};
	}

	private function settings(array $map): void
	{
		$this->settings->method('get')->willReturnCallback(
			fn (string $k, $d = '') => $map[$k] ?? ($d ?? ''));
	}

	public function testNotConfiguredWithoutSiblingApp(): void
	{
		$this->apps->method('isEnabledForUser')->willReturn(false);
		$this->settings(['crm_sync_enabled' => '1', 'crm_sync_token' => 'x']);
		// sibling class exists in this test env (customercheck enabled) but
		// isEnabledForUser=false must still gate — isConfigured is false
		self::assertFalse($this->svc()->isConfigured());
	}

	public function testNotConfiguredWhenDisabledOrNoToken(): void
	{
		$this->apps->method('isEnabledForUser')->willReturn(true);
		$this->settings(['crm_sync_enabled' => '0', 'crm_sync_token' => 'x']);
		self::assertFalse($this->svc()->isConfigured());
		$this->settings(['crm_sync_enabled' => '1', 'crm_sync_token' => '']);
		self::assertFalse($this->svc()->isConfigured());
	}

	public function testDeliverSendsContractPayload(): void
	{
		$this->apps->method('isEnabledForUser')->willReturn(true);
		$this->settings(['crm_sync_enabled' => '1', 'crm_sync_token' => 'tok_x']);

		$seen = null;
		$svc = $this->svcProbing(function (string $token, array $body) use (&$seen) {
			$seen = ['token' => $token, 'body' => $body];
		});
		self::assertTrue($svc->deliver([
			'order_ref' => 'BCK-2026-X1', 'email' => 'buyer@x.test',
			'name' => 'Bea Buyer', 'company' => '', 'locale' => 'de-DE',
			'offering' => 'Yoga Kurs', 'seats' => 2,
			'amount_cents' => 5000, 'paid_date' => '2026-10-19']));

		self::assertSame('tok_x', $seen['token']);
		self::assertSame('bck-BCK-2026-X1', $seen['body']['orderId']);
		self::assertSame('buyer@x.test', $seen['body']['email']);
		self::assertSame('Bea Buyer', $seen['body']['contactName']);
		self::assertSame('Bea Buyer', $seen['body']['companyName']); // name fallback
		self::assertSame('de', $seen['body']['lang']);
		$ag = $seen['body']['agreements'][0];
		self::assertSame('bookingcheck', $ag['productCode']);
		self::assertSame('Booking: Yoga Kurs', $ag['label']);
		self::assertSame(2, $ag['seats']);
		self::assertSame(5000, $ag['amountCents']);
		self::assertSame('2026-10-19', $ag['startsAt']);
		self::assertSame('2027-10-19', $ag['validUntil']);
	}

	public function testDeliverFalseWhenSiblingThrows(): void
	{
		$this->apps->method('isEnabledForUser')->willReturn(true);
		$this->settings(['crm_sync_enabled' => '1', 'crm_sync_token' => 'tok_x']);
		$svc = $this->svcProbing(function () {
			throw new \RuntimeException('sibling exploded');
		});
		self::assertFalse($svc->deliver([
			'order_ref' => 'R2', 'email' => 'a@b.c', 'name' => 'A',
			'company' => 'ACME', 'locale' => 'en']));
	}
}

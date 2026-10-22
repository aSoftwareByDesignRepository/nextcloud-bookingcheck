<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit\Service;

use OCA\BookingCheck\Db\Order;
use OCA\BookingCheck\Service\AuditService;
use OCA\BookingCheck\Service\EmailService;
use OCA\BookingCheck\Service\SellerNotifyService;
use OCA\BookingCheck\Service\SettingsService;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\L10N\IFactory as IL10NFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

final class SellerNotifyServiceTest extends TestCase
{
	private INotificationManager&MockObject $nm;
	private EmailService&MockObject $email;
	private AuditService&MockObject $audit;
	private IConfig&MockObject $config;

	protected function setUp(): void
	{
		$this->nm = $this->createMock(INotificationManager::class);
		$this->email = $this->createMock(EmailService::class);
		$this->audit = $this->createMock(AuditService::class);
		$this->config = $this->createMock(IConfig::class);
	}

	private function svc(?IL10NFactory $l10n = null, ?IUserManager $users = null): SellerNotifyService
	{
		$settings = $this->createMock(SettingsService::class);
		$settings->method('get')->willReturnCallback(
			fn (string $k, $d = '') => $k === 'seller_email' ? 'shop@x.test' : ($d ?? ''));
		$l10n ??= $this->createMock(IL10NFactory::class);
		$l10n->method('getUserLanguage')->willReturn('en');
		return new SellerNotifyService($this->nm, $this->email, $settings,
			$this->audit, $this->config,
			$users ?? $this->createMock(IUserManager::class),
			$l10n, $this->createMock(LoggerInterface::class));
	}

	private function order(int $isTest = 0): Order
	{
		$o = new Order();
		$o->setId(42);
		$o->setRef('BCK-2026-TST');
		$o->setOfferingId(7);
		$o->setBuyerEmail('b@x.test');
		$o->setBuyerLocale('en');
		$o->setAmountCents(2500);
		$o->setCurrency('EUR');
		$o->setIsTest($isTest);
		return $o;
	}

	public function testOptOutSendsNothing(): void
	{
		$this->config->method('getUserValue')->willReturn('0');
		$this->nm->expects($this->never())->method('notify');
		$this->email->expects($this->never())->method('queue');
		$this->svc()->orderPaid($this->order(), 'seller1', 'Yoga');
	}

	public function testPaidSendsNotificationAndEmail(): void
	{
		$this->config->method('getUserValue')->willReturn('1');
		$this->audit->method('countRecentActions')->willReturn(0);

		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->with('seller1')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->with('order', '42')->willReturnSelf();
		$notification->expects($this->once())->method('setSubject')
			->with('order_paid', $this->callback(
				fn ($p) => $p['ref'] === 'BCK-2026-TST' && $p['test'] === '0'))
			->willReturnSelf();
		$this->nm->method('createNotification')->willReturn($notification);
		$this->nm->expects($this->once())->method('notify')->with($notification);
		$this->email->expects($this->once())->method('queue')
			->with('seller_order_paid', 'shop@x.test', 'en',
				$this->callback(fn ($p) => $p['amount'] === 25 && $p['test'] === false), true);

		$this->svc()->orderPaid($this->order(), 'seller1', 'Yoga');
	}

	public function testPaidEmailUsesSellerUiLanguage(): void
	{
		// a German-speaking seller must get German order notifications,
		// not hardcoded English and not the buyer's locale
		$this->config->method('getUserValue')->willReturn('1');
		$this->audit->method('countRecentActions')->willReturn(25); // NC ping capped away
		$user = $this->createMock(\OCP\IUser::class);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->with('seller1')->willReturn($user);
		$l10n = $this->createMock(IL10NFactory::class);
		$l10n->method('getUserLanguage')->with($user)->willReturn('de');
		$this->email->expects($this->once())->method('queue')
			->with('seller_order_paid', 'shop@x.test', 'de',
				$this->anything(), true);
		$this->svc($l10n, $users)->orderPaid($this->order(), 'seller1', 'Yoga');
	}

	public function testRateCapSkipsNcPingButKeepsEmail(): void
	{
		$this->config->method('getUserValue')->willReturn('1');
		$this->audit->method('countRecentActions')->willReturn(25); // over cap
		$this->nm->expects($this->never())->method('notify');
		$this->email->expects($this->once())->method('queue');
		$this->svc()->orderPaid($this->order(), 'seller1', 'Yoga');
	}

	public function testTestOrderMarkedInSubjectParams(): void
	{
		$this->config->method('getUserValue')->willReturn('1');
		$this->audit->method('countRecentActions')->willReturn(0);
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->expects($this->once())->method('setSubject')
			->with('order_paid', $this->callback(fn ($p) => $p['test'] === '1'))
			->willReturnSelf();
		$this->nm->method('createNotification')->willReturn($notification);
		$this->nm->method('notify');
		$this->svc()->orderPaid($this->order(1), 'seller1', 'Yoga');
	}

	public function testNotificationFailureDoesNotPropagate(): void
	{
		$this->config->method('getUserValue')->willReturn('1');
		$this->audit->method('countRecentActions')->willReturn(0);
		$this->nm->method('createNotification')
			->willThrowException(new \RuntimeException('notifier broken'));
		// must swallow — the paid transition is already committed
		$this->svc()->orderPaid($this->order(), 'seller1', 'Yoga');
		$this->addToAssertionCount(1);
	}
}

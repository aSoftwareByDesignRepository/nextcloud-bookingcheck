<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit\Dashboard;

use OCA\BookingCheck\Dashboard\BookingsWidget;
use OCA\BookingCheck\Service\DashboardStatsService;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

final class BookingsWidgetTest extends TestCase
{
	private DashboardStatsService&MockObject $stats;
	private BookingsWidget $widget;

	protected function setUp(): void
	{
		$this->stats = $this->createMock(DashboardStatsService::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn ($t, $p = []) => $p ? vsprintf(str_replace('%1$', '%', $t), $p) : $t);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRoute')->willReturnCallback(fn ($r) => '/apps/' . $r);
		$url->method('imagePath')->willReturn('/img/icon.svg');
		$url->method('getAbsoluteURL')->willReturnCallback(fn ($p) => 'https://nc' . $p);
		$this->widget = new BookingsWidget($l10n, $url, $this->stats);
	}

	public function testUnauthorizedUserGetsEmptyHonestState(): void
	{
		$this->stats->method('canSee')->with('random-uid')->willReturn(false);
		$this->stats->expects($this->never())->method('todayCheckins');
		$this->stats->expects($this->never())->method('weekSales');
		$this->stats->expects($this->never())->method('upcoming');

		$items = $this->widget->getItemsV2('random-uid');
		$this->assertSame([], $items->getItems());
		$this->assertSame([], $this->widget->getWidgetButtons('random-uid'));
	}

	public function testSellerGetsStatsItemsAndButton(): void
	{
		$this->stats->method('canSee')->with('seller')->willReturn(true);
		$this->stats->method('todayCheckins')->willReturn(3);
		$this->stats->method('weekSales')->willReturn(['count' => 2, 'cents' => 5000]);
		$this->stats->method('upcoming')->willReturn([
			['title' => 'Workshop', 'starts' => '2026-10-20 09:00:00'],
		]);

		$items = $this->widget->getItemsV2('seller');
		$this->assertCount(3, $items->getItems()); // check-ins + sales + 1 upcoming
		$this->assertNotEmpty($items->getHalfEmptyContentMessage()); // at-a-glance summary
		$this->assertNotEmpty($this->widget->getWidgetButtons('seller'));
	}

	public function testHonestZeroStateStillRendersStatLines(): void
	{
		$this->stats->method('canSee')->willReturn(true);
		$this->stats->method('todayCheckins')->willReturn(0);
		$this->stats->method('weekSales')->willReturn(['count' => 0, 'cents' => 0]);
		$this->stats->method('upcoming')->willReturn([]);

		$items = $this->widget->getItemsV2('seller');
		$this->assertCount(2, $items->getItems()); // zero counts render, not hidden
	}

	public function testWidgetIdStable(): void
	{
		$this->assertSame('bookingcheck-overview', $this->widget->getId());
		$this->assertSame(300, $this->widget->getReloadInterval());
	}
}

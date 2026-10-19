<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Dashboard;

use OCA\BookingCheck\Service\DashboardStatsService;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IReloadableWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Seller desklet (US-523): today's check-ins, 7-day sales, next
 * occurrences. Role-scoped — non-sellers get an empty, honest state.
 */
class BookingsWidget implements IAPIWidgetV2, IButtonWidget, IIconWidget, IReloadableWidget
{
	private const RELOAD_SECONDS = 300;

	public function __construct(
		private readonly IL10N $l10n,
		private readonly IURLGenerator $url,
		private readonly DashboardStatsService $stats,
	) {
	}

	public function getId(): string
	{
		return 'bookingcheck-overview';
	}

	public function getTitle(): string
	{
		return $this->l10n->t('BookingCheck');
	}

	public function getOrder(): int
	{
		return 35;
	}

	public function getIconClass(): string
	{
		return 'icon-dashboard';
	}

	public function getIconUrl(): string
	{
		return $this->url->getAbsoluteURL(
			$this->url->imagePath('bookingcheck', 'app-dashboard.svg'));
	}

	public function getUrl(): ?string
	{
		return $this->url->getAbsoluteURL($this->url->linkToRoute('bookingcheck.page.index'));
	}

	public function getReloadInterval(): int
	{
		return self::RELOAD_SECONDS;
	}

	public function load(): void
	{
		\OCP\Util::addStyle('bookingcheck', 'common/tokens');
		\OCP\Util::addStyle('bookingcheck', 'desklet-nextcloud');
	}

	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems
	{
		if (!$this->stats->canSee($userId)) {
			return new WidgetItems([], $this->l10n->t('No access — seller role required.'), '');
		}

		$icon = $this->getIconUrl();
		$checkins = $this->stats->todayCheckins();
		$sales = $this->stats->weekSales();
		$money = number_format($sales['cents'] / 100, 2);

		$items = [
			new WidgetItem(
				$this->l10n->t('Check-ins today: %d', [$checkins]),
				$this->l10n->t('Across all occurrences'),
				$this->absolute('bookingcheck.roster.index'),
				$icon, 'ck-checkins'),
			new WidgetItem(
				$this->l10n->t('Sales last 7 days: %1$s (%2$d orders)', [$money, $sales['count']]),
				$this->l10n->t('Paid orders, test excluded'),
				$this->absolute('bookingcheck.order.index'),
				$icon, 'ck-sales'),
		];
		foreach ($this->stats->upcoming(5) as $i => $occ) {
			$items[] = new WidgetItem(
				$occ['title'],
				$occ['starts'],
				$this->absolute('bookingcheck.offering.index'),
				$icon, 'ck-up-' . $i);
		}

		$atAGlance = $this->l10n->t('%1$d check-ins · %2$s this week', [$checkins, $money]);
		return new WidgetItems($items, '', $atAGlance);
	}

	public function getWidgetButtons(string $userId): array
	{
		if (!$this->stats->canSee($userId)) {
			return [];
		}
		return [new WidgetButton(
			WidgetButton::TYPE_MORE,
			$this->absolute('bookingcheck.page.index'),
			$this->l10n->t('Open BookingCheck'))];
	}

	private function absolute(string $route): string
	{
		return $this->url->getAbsoluteURL($this->url->linkToRoute($route));
	}
}

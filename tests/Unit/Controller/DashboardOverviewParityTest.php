<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Tests\Unit\Controller;

use Test\TestCase;

/**
 * Overview stat-strip parity: DashboardController::statsData() emits
 * snake_case keys while the template once read camelCase (byStatus,
 * revenueCents, published) — the strip rendered zeros for a populated
 * DB and nothing failed loudly. This test asserts every top-level
 * `$stats['k']` read in templates/admin/overview.php resolves against
 * the keys statsData() actually returns.
 */
final class DashboardOverviewParityTest extends TestCase
{
	private const APP_ROOT = __DIR__ . '/../../..';

	public function testStatStripReadsResolveAgainstStatsDataEmit(): void
	{
		$tpl = (string)file_get_contents(
			self::APP_ROOT . '/templates/admin/overview.php');
		$ctrl = (string)file_get_contents(
			self::APP_ROOT . '/lib/Controller/DashboardController.php');

		preg_match_all("/\\\$stats\['([A-Za-z_][A-Za-z0-9_]*)'\]/", $tpl, $m);
		$reads = array_values(array_unique($m[1]));

		// Vacuity guards: the extraction must find the reads it audits.
		$this->assertContains('orders_by_status', $reads);
		$this->assertContains('revenue_cents', $reads);
		$this->assertContains('published_offerings', $reads);

		$sm = [];
		$this->assertSame(1, preg_match(
			'/function\s+statsData\s*\([^)]*\)\s*:\s*array\s*\{(.*)\n\t\}/s',
			$ctrl, $sm), 'statsData() body not found');
		$body = $sm[1];
		$this->assertSame(1, preg_match('/return\s*\[(.*?)\];/s', $body, $rm),
			'statsData() return literal not found');
		preg_match_all("/'([A-Za-z_][A-Za-z0-9_]*)'\s*=>/", $rm[1], $km);
		$emits = array_values(array_unique($km[1]));
		$this->assertContains('orders_by_status', $emits);

		foreach ($reads as $key) {
			$this->assertContains($key, $emits,
				"overview.php reads \$stats['$key'] — statsData() does not emit it");
		}
	}
}

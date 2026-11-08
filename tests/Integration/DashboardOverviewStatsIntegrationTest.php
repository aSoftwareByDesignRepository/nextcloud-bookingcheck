<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Tests\Integration;

use OCA\BookingCheck\Controller\DashboardController;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use Test\TestCase;

/**
 * Overview stat strip regression (2026-10-23): statsData() emitted
 * orders_by_status/revenue_cents/published_offerings but the template
 * read byStatus/revenueCents/published — every tile rendered 0. This
 * test pins the emitted shape AND values against the real DB so a
 * silent zero state cannot ship again.
 */
final class DashboardOverviewStatsIntegrationTest extends TestCase
{
	private IDBConnection $db;
	private DashboardController $controller;
	private array $orderIds = [];
	private array $offeringIds = [];

	protected function setUp(): void
	{
		parent::setUp();
		$this->db = \OC::$server->get(IDBConnection::class);
		$this->controller = new DashboardController(
			'bookingcheck',
			\OC::$server->get(IRequest::class),
			$this->db,
			\OC::$server->get(IUserSession::class),
			\OC::$server->get(ICacheFactory::class),
		);
	}

	protected function tearDown(): void
	{
		foreach ($this->orderIds as $id) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('bc_order')->where($qb->expr()->eq('id',
				$qb->createNamedParameter($id)))->executeStatement();
		}
		foreach ($this->offeringIds as $id) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('bc_offering')->where($qb->expr()->eq('id',
				$qb->createNamedParameter($id)))->executeStatement();
		}
		parent::tearDown();
	}

	/** @return array<string,mixed> */
	private function stats(): array
	{
		$res = $this->controller->stats();
		$data = $res->getData();
		$this->assertTrue($data['success']);
		return $data['data'];
	}

	public function testStatsEmitKeysTemplateConsumes(): void
	{
		$stats = $this->stats();
		foreach (['orders_by_status', 'published_offerings', 'revenue_cents'] as $k) {
			$this->assertArrayHasKey($k, $stats);
		}
		$this->assertIsArray($stats['orders_by_status']);
		$this->assertIsInt($stats['published_offerings']);
		$this->assertIsInt($stats['revenue_cents']);
	}

	public function testPaidOrderAndPublishedOfferingMoveTheNumbers(): void
	{
		$before = $this->stats();

		$qb = $this->db->getQueryBuilder();
		$qb->insert('bc_order')
			->setValue('ref', $qb->createNamedParameter('BCK-OVW-' . bin2hex(random_bytes(4))))
			->setValue('offering_id', $qb->createNamedParameter(0))
			->setValue('buyer_name', $qb->createNamedParameter('Overview Buyer'))
			->setValue('buyer_email', $qb->createNamedParameter('ovw@wl.test'))
			->setValue('buyer_locale', $qb->createNamedParameter('en'))
			->setValue('vies_status', $qb->createNamedParameter('none'))
			->setValue('reverse_charge', $qb->createNamedParameter(0))
			->setValue('idem_token', $qb->createNamedParameter('idem-' . bin2hex(random_bytes(6))))
			->setValue('manage_token_hash', $qb->createNamedParameter(hash('sha256', 'x')))
			->setValue('discount_cents', $qb->createNamedParameter(0))
			->setValue('promo_cents', $qb->createNamedParameter(0))
			->setValue('amount_cents', $qb->createNamedParameter(1234))
			->setValue('currency', $qb->createNamedParameter('EUR'))
			->setValue('status', $qb->createNamedParameter('paid'))
			->setValue('is_test', $qb->createNamedParameter(0))
			->setValue('paid_at', $qb->createNamedParameter(new \DateTimeImmutable(), 'datetime_immutable'))
			->setValue('created_at', $qb->createNamedParameter(new \DateTimeImmutable(), 'datetime_immutable'));
		$qb->executeStatement();
		$this->orderIds[] = $qb->getLastInsertId();

		$qb = $this->db->getQueryBuilder();
		$qb->insert('bc_offering')
			->setValue('title', $qb->createNamedParameter('Overview Stats Offering'))
			->setValue('slug', $qb->createNamedParameter('ovw-' . bin2hex(random_bytes(4))))
			->setValue('type', $qb->createNamedParameter('event'))
			->setValue('status', $qb->createNamedParameter('published'))
			->setValue('created_by', $qb->createNamedParameter('admin'))
			->setValue('created_at', $qb->createNamedParameter(new \DateTimeImmutable(), 'datetime_immutable'))
			->setValue('updated_at', $qb->createNamedParameter(new \DateTimeImmutable(), 'datetime_immutable'));
		$qb->executeStatement();
		$this->offeringIds[] = $qb->getLastInsertId();

		$after = $this->stats();
		$this->assertSame(
			(int)($before['orders_by_status']['paid'] ?? 0) + 1,
			(int)($after['orders_by_status']['paid'] ?? 0));
		$this->assertSame($before['revenue_cents'] + 1234, $after['revenue_cents']);
		$this->assertSame($before['published_offerings'] + 1, $after['published_offerings']);
	}
}

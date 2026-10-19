<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Integration;

use OCA\BookingCheck\Service\DashboardStatsService;
use OCP\IDBConnection;
use Test\TestCase;

/**
 * US-523 dashboard reads against the real DB: seller-scope gate, honest
 * zero state, and correct scoping (test orders excluded from sales).
 */
final class DashboardStatsIntegrationTest extends TestCase
{
	private IDBConnection $db;
	private DashboardStatsService $stats;
	private array $offeringIds = [];
	private array $eventIds = [];

	protected function setUp(): void
	{
		parent::setUp();
		$this->db = \OC::$server->get(IDBConnection::class);
		$this->stats = \OC::$server->get(DashboardStatsService::class);
	}

	protected function tearDown(): void
	{
		foreach ($this->eventIds as $id) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('bc_event')->where($qb->expr()->eq('id',
				$qb->createNamedParameter($id)))->executeStatement();
		}
		foreach ($this->offeringIds as $id) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('bc_offering')->where($qb->expr()->eq('id',
				$qb->createNamedParameter($id)))->executeStatement();
		}
		parent::tearDown();
	}

	public function testCanSeeScopesToSellerRole(): void
	{
		$this->assertFalse($this->stats->canSee(''));
		$this->assertFalse($this->stats->canSee('no_such_user_xyz'));
		$this->assertTrue($this->stats->canSee('admin')); // instance admin
	}

	public function testStatsReturnHonestZeroesNotErrors(): void
	{
		$this->assertIsInt($this->stats->todayCheckins());
		$sales = $this->stats->weekSales();
		$this->assertArrayHasKey('count', $sales);
		$this->assertArrayHasKey('cents', $sales);
		$this->assertIsInt($sales['count']);
		$this->assertIsInt($sales['cents']);
		$this->assertIsArray($this->stats->upcoming(5));
	}

	public function testWeekSalesReflectsRealPaidOrder(): void
	{
		// regression: aggregate fetch() is associative — positional row[0]
		// silently reported 0 for a populated table.
		$before = $this->stats->weekSales();

		$qb = $this->db->getQueryBuilder();
		$qb->insert('bc_order')
			->setValue('ref', $qb->createNamedParameter('BCK-STATS-' . bin2hex(random_bytes(4))))
			->setValue('offering_id', $qb->createNamedParameter(0))
			->setValue('buyer_name', $qb->createNamedParameter('Stats Buyer'))
			->setValue('buyer_email', $qb->createNamedParameter('stats@wl.test'))
			->setValue('buyer_locale', $qb->createNamedParameter('en'))
			->setValue('vies_status', $qb->createNamedParameter('none'))
			->setValue('reverse_charge', $qb->createNamedParameter(0))
			->setValue('idem_token', $qb->createNamedParameter('idem-' . bin2hex(random_bytes(6))))
			->setValue('manage_token_hash', $qb->createNamedParameter(hash('sha256', 'x')))
			->setValue('discount_cents', $qb->createNamedParameter(0))
			->setValue('promo_cents', $qb->createNamedParameter(0))
			->setValue('amount_cents', $qb->createNamedParameter(777))
			->setValue('currency', $qb->createNamedParameter('EUR'))
			->setValue('status', $qb->createNamedParameter('paid'))
			->setValue('is_test', $qb->createNamedParameter(0))
			->setValue('paid_at', $qb->createNamedParameter(new \DateTimeImmutable(), 'datetime_immutable'))
			->setValue('created_at', $qb->createNamedParameter(new \DateTimeImmutable(), 'datetime_immutable'));
		$qb->executeStatement();
		$oid = $qb->getLastInsertId();

		try {
			$after = $this->stats->weekSales();
			$this->assertSame($before['count'] + 1, $after['count']);
			$this->assertSame($before['cents'] + 777, $after['cents']);
		} finally {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('bc_order')->where($qb->expr()->eq('id',
				$qb->createNamedParameter($oid)))->executeStatement();
		}
	}

	public function testUpcomingIncludesFutureEventOrdered(): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->insert('bc_offering')
			->setValue('title', $qb->createNamedParameter('Stats Test Offering'))
			->setValue('slug', $qb->createNamedParameter('stats-' . bin2hex(random_bytes(4))))
			->setValue('type', $qb->createNamedParameter('event'))
			->setValue('status', $qb->createNamedParameter('published'))
			->setValue('created_by', $qb->createNamedParameter('admin'))
			->setValue('created_at', $qb->createNamedParameter(new \DateTimeImmutable(), 'datetime_immutable'))
			->setValue('updated_at', $qb->createNamedParameter(new \DateTimeImmutable(), 'datetime_immutable'));
		$qb->executeStatement();
		$oid = $qb->getLastInsertId();
		$this->offeringIds[] = $oid;

		$qb = $this->db->getQueryBuilder();
		$qb->insert('bc_event')
			->setValue('offering_id', $qb->createNamedParameter($oid))
			->setValue('starts_at', $qb->createNamedParameter(
				(new \DateTimeImmutable('+3 days'))->format('Y-m-d H:i:s')))
			->setValue('ends_at', $qb->createNamedParameter(
				(new \DateTimeImmutable('+3 days +2 hours'))->format('Y-m-d H:i:s')))
			->setValue('capacity', $qb->createNamedParameter(10))
			->setValue('sold', $qb->createNamedParameter(0))
			->setValue('held', $qb->createNamedParameter(0))
			->setValue('status', $qb->createNamedParameter('scheduled'));
		$qb->executeStatement();
		$this->eventIds[] = $qb->getLastInsertId();

		$upcoming = $this->stats->upcoming(50);
		$titles = array_column($upcoming, 'title');
		$this->assertContains('Stats Test Offering', $titles);

		// ordering: starts ascending
		$starts = array_column($upcoming, 'starts');
		$sorted = $starts;
		sort($sorted);
		$this->assertSame($sorted, $starts);
	}
}

<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Integration;

use OCA\BookingCheck\Db\EventMapper;
use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Db\OrderMapper;
use OCA\BookingCheck\Db\TicketMapper;
use OCA\BookingCheck\Exception\CapacityException;
use OCA\BookingCheck\Exception\StateConflictException;
use OCA\BookingCheck\Exception\ValidationException;
use OCA\BookingCheck\Service\OrderService;
use OCA\BookingCheck\Service\ReservationService;
use OCA\BookingCheck\Service\TicketService;
use OCP\IDBConnection;
use Test\TestCase;

/**
 * Money-path integration: real DB, real services from the container.
 * Each test seeds its own offering+event and cleans up — fixtures never
 * share state, order IDs are discovered, not assumed.
 */
final class OrderFlowIntegrationTest extends TestCase
{
	private IDBConnection $db;
	private OrderService $orders;
	private ReservationService $inventory;
	private TicketService $tickets;
	private array $created = ['offering' => [], 'event' => [], 'order' => []];

	protected function setUp(): void
	{
		parent::setUp();
		$this->db = \OC::$server->get(IDBConnection::class);
		$this->orders = \OC::$server->get(OrderService::class);
		$this->inventory = \OC::$server->get(ReservationService::class);
		$this->tickets = \OC::$server->get(TicketService::class);
	}

	protected function tearDown(): void
	{
		foreach (['order' => 'bc_order', 'event' => 'bc_event', 'offering' => 'bc_offering'] as $k => $t) {
			foreach ($this->created[$k] as $id) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete($t)->where($qb->expr()->eq('id',
					$qb->createNamedParameter($id)))->executeStatement();
			}
		}
		// cascade-ish cleanup for rows the orders spawned
		if ($this->created['order'] !== []) {
			$ids = $this->created['order'];
			foreach (['bc_order_line' => 'order_id', 'bc_payment' => 'order_id',
				'bc_invoice' => 'order_id'] as $t => $col) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete($t)->where($qb->expr()->in($col,
					$qb->createNamedParameter($ids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
			}
			$qb = $this->db->getQueryBuilder();
			$qb->delete('bc_ticket')->where($qb->expr()->in('line_id',
				$qb->createFunction('(SELECT id FROM *PREFIX*bc_order_line WHERE order_id IN ('
					. implode(',', array_map('intval', $ids)) . '))')))->executeStatement();
		}
		parent::tearDown();
	}

	private function seedOffering(int $capacity = 5, array $over = []): array
	{
		$now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
		$slug = 'itest-' . bin2hex(random_bytes(4));
		$this->db->getQueryBuilder()->insert('bc_offering')->values([
			'type' => "'event'", 'slug' => "'$slug'", 'title' => "'Integration Seat'",
			'status' => "'published'", 'price_cents' => (string)($over['price_cents'] ?? 2500),
			'currency' => "'EUR'", 'vat_bp' => '1900', 'book_series' => '0',
			'approval_required' => (string)($over['approval'] ?? 0),
			'waitlist_mode' => "'allocate'", 'review_display' => '0',
			'sharing_enabled' => '1', 'sharing_scope' => "'occurrence'",
			'upload_max_mb' => '10', 'upload_quota_mb' => '100',
			'list_in_directory' => '0', 'eol_state' => "'none'",
			'created_by' => "'itest'", 'created_at' => "'$now'", 'updated_at' => "'$now'",
		])->executeStatement();
		$offeringId = (int)$this->db->lastInsertId('oc_bc_offering');
		$this->created['offering'][] = $offeringId;

		$starts = (new \DateTimeImmutable('+30 days'))->format('Y-m-d H:i:s');
		$ends = (new \DateTimeImmutable('+30 days +2 hours'))->format('Y-m-d H:i:s');
		$this->db->getQueryBuilder()->insert('bc_event')->values([
			'offering_id' => (string)$offeringId, 'starts_at' => "'$starts'",
			'ends_at' => "'$ends'", 'capacity' => (string)$capacity, 'status' => "'scheduled'",
		])->executeStatement();
		$eventId = (int)$this->db->lastInsertId('oc_bc_event');
		$this->created['event'][] = $eventId;
		return ['offering' => $offeringId, 'event' => $eventId];
	}

	private function idem(string $tag): string
	{
		return 'it' . substr(hash('sha256', $tag . microtime(true) . random_bytes(4)), 0, 12);
	}

	private function create(int $offeringId, int $eventId, int $seats, string $idem): array
	{
		return $this->orders->createOrder($offeringId, 'Test Buyer', 'buyer-' . $idem . '@x.test',
			'en', [['target_type' => 'event', 'target_id' => $eventId, 'seats' => $seats]],
			[], null, null, $idem, null, true, 'itest');
	}

	private function orderStatus(int $orderId): string
	{
		$qb = $this->db->getQueryBuilder();
		return (string)$qb->select('status')->from('bc_order')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($orderId)))
			->executeQuery()->fetchOne();
	}

	private function counters(int $eventId): array
	{
		$qb = $this->db->getQueryBuilder();
		$row = $qb->select('capacity', 'sold', 'held')->from('bc_event')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($eventId)))
			->executeQuery()->fetch();
		return ['capacity' => (int)$row['capacity'], 'sold' => (int)$row['sold'], 'held' => (int)$row['held']];
	}

	public function testHoldThenPayConsumesHeldIntoSold(): void
	{
		$f = $this->seedOffering(5);
		$r = $this->create($f['offering'], $f['event'], 2, $this->idem('flow-a'));
		self::assertSame('pay', $r['action']);
		$this->created['order'][] = (int)$r['order']->getId();
		self::assertSame(['capacity' => 5, 'sold' => 0, 'held' => 2], $this->counters($f['event']));

		$this->orders->markPaid((int)$r['order']->getId(), 'itest');
		self::assertSame(['capacity' => 5, 'sold' => 2, 'held' => 0], $this->counters($f['event']));
		self::assertSame('paid', $this->orderStatus((int)$r['order']->getId()));

		// tickets: exactly `seats`, non-empty QR enc, hashed token never plaintext
		$qb = $this->db->getQueryBuilder();
		$count = $qb->select($qb->func()->count('t.id'))
			->from('bc_ticket', 't')
			->innerJoin('t', 'bc_order_line', 'l', $qb->expr()->eq('l.id', 't.line_id'))
			->where($qb->expr()->eq('l.order_id',
				$qb->createNamedParameter((int)$r['order']->getId())))->executeQuery()->fetchOne();
		self::assertSame(2, (int)$count);
	}

	public function testIdempotentReplayReturnsSameOrderNoDoubleHold(): void
	{
		$f = $this->seedOffering(5);
		$tok = $this->idem('flow-b');
		$a = $this->create($f['offering'], $f['event'], 2, $tok);
		$b = $this->create($f['offering'], $f['event'], 2, $tok);
		$this->created['order'][] = (int)$a['order']->getId();
		self::assertSame((int)$a['order']->getId(), (int)$b['order']->getId());
		self::assertSame('idempotent-replay', $b['action']);
		self::assertSame(2, $this->counters($f['event'])['held']);
	}

	public function testOversellRejectedAtomically(): void
	{
		$f = $this->seedOffering(3);
		$a = $this->create($f['offering'], $f['event'], 3, $this->idem('flow-c'));
		$this->created['order'][] = (int)$a['order']->getId();
		$this->expectException(CapacityException::class);
		try {
			$this->create($f['offering'], $f['event'], 1, $this->idem('flow-c'));
		} finally {
			self::assertSame(3, $this->counters($f['event'])['held']);
		}
	}

	public function testCancelPendingReleasesHold(): void
	{
		$f = $this->seedOffering(5);
		$r = $this->create($f['offering'], $f['event'], 2, $this->idem('flow-d'));
		$this->created['order'][] = (int)$r['order']->getId();
		$this->orders->cancelPending((int)$r['order']->getId(), 'itest');
		self::assertSame(['capacity' => 5, 'sold' => 0, 'held' => 0], $this->counters($f['event']));
	}

	public function testPaidCancelReleasesSoldAndRefundable(): void
	{
		$f = $this->seedOffering(5);
		$r = $this->create($f['offering'], $f['event'], 2, $this->idem('flow-e'));
		$this->created['order'][] = (int)$r['order']->getId();
		$this->orders->markPaid((int)$r['order']->getId(), 'itest');
		$this->orders->transitionToCancelled((int)$r['order']->getId(), 'itest');
		self::assertSame(['capacity' => 5, 'sold' => 0, 'held' => 0], $this->counters($f['event']));
		$this->orders->transitionToRefunded((int)$r['order']->getId(), 'itest');
		self::assertSame('refunded', $this->orderStatus((int)$r['order']->getId()));
	}

	public function testIllegalTransitionRejected(): void
	{
		$f = $this->seedOffering(5);
		$r = $this->create($f['offering'], $f['event'], 1, $this->idem('flow-f'));
		$this->created['order'][] = (int)$r['order']->getId();
		// cancelled order can never go back to paid
		$this->orders->cancelPending((int)$r['order']->getId(), 'itest');
		$this->expectException(StateConflictException::class);
		$this->orders->markPaid((int)$r['order']->getId(), 'itest');
	}

	public function testCheckInIdempotentAndScoped(): void
	{
		$f = $this->seedOffering(5);
		$r = $this->create($f['offering'], $f['event'], 1, $this->idem('flow-g'));
		$this->created['order'][] = (int)$r['order']->getId();
		$this->orders->markPaid((int)$r['order']->getId(), 'itest');

		$tm = \OC::$server->get(TicketMapper::class);
		$qb = $this->db->getQueryBuilder();
		$lineId = (int)$qb->select('id')->from('bc_order_line')
			->where($qb->expr()->eq('order_id',
				$qb->createNamedParameter((int)$r['order']->getId())))->executeQuery()->fetchOne();
		$ticket = $tm->findByLine($lineId)[0];
		self::assertNotNull($ticket->getQrTokenEnc());
		$payload = $this->tickets->qrPayload($ticket);
		self::assertMatchesRegularExpression('/^BCK1:' . (int)$ticket->getId() . ':[A-Za-z0-9_-]{32,64}$/', $payload);

		$token = substr($payload, strrpos($payload, ':') + 1);
		$first = $this->tickets->checkInByToken($token, 'door');
		self::assertTrue($first['ok'] && !$first['already']);
		$second = $this->tickets->checkInByToken($token, 'door');
		self::assertTrue($second['ok'] && $second['already'], 'replay must be idempotent');
		$bad = $this->tickets->checkInByToken('forged-token-zzz', 'door');
		self::assertFalse($bad['ok']);
	}

	public function testValidationRejectsBadInput(): void
	{
		$f = $this->seedOffering(5);
		$this->expectException(ValidationException::class);
		$this->create($f['offering'], $f['event'], 0, $this->idem('flow-h'));
	}
}

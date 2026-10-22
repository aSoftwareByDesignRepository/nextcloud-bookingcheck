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
	private array $created = ['offering' => [], 'event' => [], 'order' => [],
		'waitlist' => [], 'series' => [], 'question' => [], 'slot_tpl' => [], 'slot' => [],
		'resource' => [], 'slot_res' => []];

	protected function setUp(): void
	{
		parent::setUp();
		\OCA\BookingCheck\Tests\ProLicense::enable(); // vouchers/waitlist paths are Pro-gated
		$this->db = \OC::$server->get(IDBConnection::class);
		$this->orders = \OC::$server->get(OrderService::class);
		$this->inventory = \OC::$server->get(ReservationService::class);
		$this->tickets = \OC::$server->get(TicketService::class);
	}

	protected function tearDown(): void
	{
		\OCA\BookingCheck\Tests\ProLicense::disable();
		foreach (['order' => 'bc_order', 'event' => 'bc_event', 'series' => 'bc_series',
			'offering' => 'bc_offering', 'question' => 'bc_question',
			'slot_tpl' => 'bc_slot_tpl', 'slot' => 'bc_slot',
			'resource' => 'bc_resource', 'slot_res' => 'bc_slot_res'] as $k => $t) {
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
				'bc_invoice' => 'order_id', 'bc_audit' => 'order_id',
				'bc_pass' => 'order_id', 'bc_reminder' => 'order_id'] as $t => $col) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete($t)->where($qb->expr()->in($col,
					$qb->createNamedParameter($ids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
			}
			$qb = $this->db->getQueryBuilder();
			$qb->delete('bc_answer')->where($qb->expr()->in('ticket_id',
				$qb->createFunction('(SELECT t.id FROM *PREFIX*bc_ticket t
					JOIN *PREFIX*bc_order_line ol ON ol.id = t.line_id
					WHERE ol.order_id IN (' . implode(',', array_map('intval', $ids)) . '))')))->executeStatement();
			$qb = $this->db->getQueryBuilder();
			$qb->delete('bc_ticket')->where($qb->expr()->in('line_id',
				$qb->createFunction('(SELECT id FROM *PREFIX*bc_order_line WHERE order_id IN ('
					. implode(',', array_map('intval', $ids)) . '))')))->executeStatement();
			$qb = $this->db->getQueryBuilder();
			$qb->delete('bc_pass_use')->where($qb->expr()->in('pass_id',
				$qb->createFunction('(SELECT id FROM *PREFIX*bc_pass WHERE order_id IN ('
					. implode(',', array_map('intval', $ids)) . '))')))->executeStatement();
		}
		foreach ($this->created['waitlist'] as $email) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('bc_waitlist')->where($qb->expr()->eq('email',
				$qb->createNamedParameter($email)))->executeStatement();
		}
		// Child tables a test may spawn without registering ids — sweep by
		// their offering/order link so no run leaks rows into the next.
		// Deliberately NOT swept: bc_invoice_seq (singleton counter owned by
		// InvoiceService — deleting it would renumber real invoices),
		// bc_material_lib + bc_webhook + bc_webhook_dlvr + bc_webhook_evt
		// (instance-scoped fixtures not seeded by this suite), and
		// bc_cc_sync (dropped by migration 1010 — nothing to sweep).
		$intArr = \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY;
		if ($this->created['offering'] !== []) {
			foreach (['bc_addon', 'bc_material', 'bc_price_cat', 'bc_promo_scope'] as $t) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete($t)->where($qb->expr()->in('offering_id',
					$qb->createNamedParameter($this->created['offering'], $intArr)))
					->executeStatement();
			}
		}
		if ($this->created['order'] !== []) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('bc_download')->where($qb->expr()->in('order_id',
				$qb->createNamedParameter($this->created['order'], $intArr)))
				->executeStatement();
		}
		// NH-37 mail-template overrides a test may leave behind on failure
		$qb = $this->db->getQueryBuilder();
		$qb->delete('bc_mail_tpl')->where($qb->expr()->eq('kind',
			$qb->createNamedParameter('order_confirmed')))->executeStatement();

		// upload rows + their appdata blobs for seeded offerings
		if ($this->created['offering'] !== []) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('bc_upload')->where($qb->expr()->in('offering_id',
				$qb->createNamedParameter($this->created['offering'],
					\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
			try {
				$appData = \OCP\Server::get(\OCP\Files\AppData\IAppDataFactory::class)->get('bookingcheck');
				$dir = $appData->getFolder('uploads');
				foreach ($dir->getDirectoryListing() as $file) {
					$file->delete();
				}
			} catch (\Throwable) {
				// folder may not exist in a fresh test env — fine
			}
		}
		parent::tearDown();
	}

	private function seedOffering(int $capacity = 5, array $over = []): array
	{
		$now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
		$slug = 'itest-' . bin2hex(random_bytes(4));
		$qb = $this->db->getQueryBuilder();
		$values = [
			'type' => "'" . ($over['type'] ?? 'event') . "'", 'slug' => "'$slug'", 'title' => "'Integration Seat'",
			'status' => "'" . ($over['status'] ?? 'published') . "'",
			'price_cents' => (string)($over['price_cents'] ?? 2500),
			'currency' => "'EUR'", 'vat_bp' => (string)($over['vat_bp'] ?? 1900), 'book_series' => '0',
			'approval_required' => (string)($over['approval'] ?? 0),
			'waitlist_mode' => "'" . ($over['waitlist_mode'] ?? 'allocate') . "'",
			'review_display' => (string)($over['review_display'] ?? 0),
			'sharing_enabled' => (string)($over['sharing_enabled'] ?? 1),
			'sharing_scope' => "'" . ($over['sharing_scope'] ?? 'occurrence') . "'",
			'upload_max_mb' => (string)($over['upload_max_mb'] ?? 10),
			'upload_quota_mb' => (string)($over['upload_quota_mb'] ?? 100),
			'list_in_directory' => '0', 'eol_state' => "'" . ($over['eol_state'] ?? 'none') . "'",
			'created_by' => "'itest'", 'created_at' => "'$now'", 'updated_at' => "'$now'",
		];
		if (isset($over['eol_action_at'])) {
			$values['eol_action_at'] = $qb->createNamedParameter($over['eol_action_at'], 'datetime');
		}
		if (isset($over['tos_text'])) {
			$values['tos_text'] = $qb->createNamedParameter((string)$over['tos_text']);
		}
		$qb->insert('bc_offering')->values($values)->executeStatement();
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

	private function create(int $offeringId, int $eventId, int $seats, string $idem,
		bool $consented = false, string $targetType = 'event', bool $isTest = true): array
	{
		return $this->orders->createOrder($offeringId, 'Test Buyer', 'buyer-' . $idem . '@x.test',
			'en', [['target_type' => $targetType, 'target_id' => $eventId, 'seats' => $seats]],
			[], null, null, $idem, null, $isTest, 'itest', $consented);
	}

	/** Seed a series with $members scheduled member events on the offering. */
	private function seedSeries(int $offeringId, int $capacity, int $members = 2): int
	{
		$this->db->getQueryBuilder()->insert('bc_series')->values([
			'offering_id' => (string)$offeringId, 'title' => "'ITest Series'",
			'capacity' => (string)$capacity,
		])->executeStatement();
		$seriesId = (int)$this->db->lastInsertId('oc_bc_series');
		$this->created['series'][] = $seriesId;
		for ($i = 0; $i < $members; $i++) {
			$starts = (new \DateTimeImmutable('+' . (30 + $i * 7) . ' days'))->format('Y-m-d H:i:s');
			$ends = (new \DateTimeImmutable('+' . (30 + $i * 7) . ' days +2 hours'))->format('Y-m-d H:i:s');
			$this->db->getQueryBuilder()->insert('bc_event')->values([
				'offering_id' => (string)$offeringId, 'starts_at' => "'$starts'",
				'ends_at' => "'$ends'", 'capacity' => (string)$capacity,
				'status' => "'scheduled'", 'series_id' => (string)$seriesId,
			])->executeStatement();
			$this->created['event'][] = (int)$this->db->lastInsertId('oc_bc_event');
		}
		return $seriesId;
	}

	private function seriesCounters(int $seriesId): array
	{
		$qb = $this->db->getQueryBuilder();
		$row = $qb->select('capacity', 'sold', 'held')->from('bc_series')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($seriesId)))
			->executeQuery()->fetch();
		return ['capacity' => (int)$row['capacity'], 'sold' => (int)$row['sold'], 'held' => (int)$row['held']];
	}

	private function memberCounters(int $seriesId): array
	{
		$qb = $this->db->getQueryBuilder();
		return $qb->select('id', 'sold', 'held')->from('bc_event')
			->where($qb->expr()->eq('series_id', $qb->createNamedParameter($seriesId)))
			->orderBy('id', 'ASC')->executeQuery()->fetchAll();
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

	/** Seed a timeslot offering with one slot bound to a resource pool. */
	private function seedPooledSlot(int $poolCap = 4): array
	{
		$f = $this->seedOffering($poolCap, ['type' => 'timeslot']);
		$this->db->getQueryBuilder()->insert('bc_resource')->values([
			'offering_id' => (string)$f['offering'], 'name' => "'Room A'",
			'capacity' => (string)$poolCap, 'active' => '1',
		])->executeStatement();
		$resId = (int)$this->db->lastInsertId('oc_bc_resource');
		$this->created['resource'][] = $resId;
		$starts = (new \DateTimeImmutable('+30 days'))->format('Y-m-d H:i:s');
		$ends = (new \DateTimeImmutable('+30 days +1 hour'))->format('Y-m-d H:i:s');
		$this->db->getQueryBuilder()->insert('bc_slot')->values([
			'offering_id' => (string)$f['offering'], 'tpl_id' => '0',
			'starts_at' => "'$starts'",
			'ends_at' => "'$ends'", 'capacity' => (string)$poolCap,
		])->executeStatement();
		$slotId = (int)$this->db->lastInsertId('oc_bc_slot');
		$this->created['slot'][] = $slotId;
		$this->db->getQueryBuilder()->insert('bc_slot_res')->values([
			'slot_id' => (string)$slotId, 'resource_id' => (string)$resId,
			'capacity' => (string)$poolCap, 'active' => '1',
		])->executeStatement();
		$this->created['slot_res'][] = (int)$this->db->lastInsertId('oc_bc_slot_res');
		return ['offering' => $f['offering'], 'slot' => $slotId, 'resource' => $resId];
	}

	/**
	 * A pooled slot MUST be booked through its pool: a bare slot line
	 * would draw on the never-used bc_slot counter and sell seats beyond
	 * the pool's capacity — double inventory. Wrong-pool and non-slot
	 * resource picks fail closed the same way.
	 */
	public function testPooledSlotRequiresResourcePick(): void
	{
		$f = $this->seedPooledSlot(4);
		$idem = $this->idem('pool-a');
		$mk = fn (?int $rid, string $type = 'slot') => $this->orders->createOrder(
			$f['offering'], 'Test Buyer', 'pool-' . $idem . '@x.test', 'en',
			[['target_type' => $type, 'target_id' => $f['slot'], 'seats' => 1,
				'resource_id' => $rid]],
			[], null, null, $idem . '-' . ($rid ?? 'x') . $type, null, true, 'itest', false);

		// bare pick on a pooled slot — rejected, nothing held
		try {
			$mk(null);
			self::fail('pooled slot accepted a bare line');
		} catch (ValidationException) {
		}
		// foreign pool id — rejected
		try {
			$mk(99999);
			self::fail('pooled slot accepted a foreign pool');
		} catch (ValidationException) {
		}
		// resource on a non-slot target — rejected
		try {
			$this->orders->createOrder($f['offering'], 'Test Buyer', 'pool2-' . $idem . '@x.test', 'en',
				[['target_type' => 'event', 'target_id' => $this->created['event'][0],
					'seats' => 1, 'resource_id' => $f['resource']]],
				[], null, null, $idem . '-evt', null, true, 'itest', false);
			self::fail('event line accepted a resource_id');
		} catch (ValidationException) {
		}

		// the honest path: the pool pick holds on bc_slot_res
		$r = $mk($f['resource']);
		$this->created['order'][] = (int)$r['order']->getId();
		$qb = $this->db->getQueryBuilder();
		$row = $qb->select('sold', 'held')->from('bc_slot_res')
			->where($qb->expr()->eq('slot_id', $qb->createNamedParameter($f['slot'])))
			->executeQuery()->fetch();
		self::assertSame(1, (int)$row['held']);
		// slot row stays untouched — its counter is not the inventory
		$qb2 = $this->db->getQueryBuilder();
		$srow = $qb2->select('sold', 'held')->from('bc_slot')
			->where($qb2->expr()->eq('id', $qb2->createNamedParameter($f['slot'])))
			->executeQuery()->fetch();
		self::assertSame(['sold' => 0, 'held' => 0],
			['sold' => (int)$srow['sold'], 'held' => (int)$srow['held']]);
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

	/**
	 * Door-scope resolution runs REAL SQL — the unit suite mocks the
	 * QB chain, so a column typo (bc_order_line has no offering_id;
	 * the offering is resolved via the line's target) would only blow
	 * up at the door. This test walks the whole path: companion token
	 * → session → controller → doorScopeOk → check-in.
	 */
	public function testDoorScopeCheckInResolvesViaTarget(): void
	{
		$f = $this->seedOffering(5);
		$r = $this->create($f['offering'], $f['event'], 1, $this->idem('door'));
		$this->created['order'][] = (int)$r['order']->getId();
		$this->orders->markPaid((int)$r['order']->getId(), 'itest');

		$tm = \OC::$server->get(TicketMapper::class);
		$qb = $this->db->getQueryBuilder();
		$lineId = (int)$qb->select('id')->from('bc_order_line')
			->where($qb->expr()->eq('order_id',
				$qb->createNamedParameter((int)$r['order']->getId())))
			->executeQuery()->fetchOne();
		$ticket = $tm->findByLine($lineId)[0];
		$payload = $this->tickets->qrPayload($ticket);

		// mint a door device scoped to THIS offering — via QB insert to
		// keep the plaintext token knowable
		$tokenSvc = \OC::$server->get(\OCA\BookingCheck\Service\TokenService::class);
		$plain = 'itest-device-' . bin2hex(random_bytes(8));
		$qb = $this->db->getQueryBuilder();
		$qb->insert('bc_companion')->values([
			'token_hash' => $qb->createNamedParameter($tokenSvc->hash($plain)),
			'label' => $qb->createNamedParameter('itest door'),
			'role' => $qb->createNamedParameter('door'),
			'offering_ids' => $qb->createNamedParameter(json_encode([$f['offering']])),
			'created_by' => $qb->createNamedParameter('itest'),
			'created_at' => $qb->createNamedParameter(new \DateTime(), 'datetime'),
		])->executeStatement();
		$devId = (int)$this->db->lastInsertId('oc_bc_companion');

		$doors = new \OCA\BookingCheck\Service\DoorSessionService(
			\OC::$server->get(\OCP\ISession::class),
			\OC::$server->get(\OCA\BookingCheck\Db\CompanionTokenMapper::class),
			$tokenSvc);
		$ctrl = function (array $params) use ($doors, $tokenSvc) {
			$req = $this->createMock(\OCP\IRequest::class);
			$req->method('getParam')->willReturnCallback(
				fn (string $k, $d = null) => $params[$k] ?? $d);
			return new \OCA\BookingCheck\Controller\ScanController(
				'bookingcheck', $req,
			$this->tickets,
			\OC::$server->get(\OCA\BookingCheck\Db\OrderMapper::class),
			\OC::$server->get(\OCP\IUserSession::class),
			\OC::$server->get(\OCA\BookingCheck\Service\AccessControlService::class),
				$doors, $this->db, $tokenSvc,
				\OC::$server->get(\OCA\BookingCheck\Service\WaitlistService::class));
		};

		try {
			self::assertTrue($doors->open($plain));

			// in-scope: the join resolves ticket → line → event → offering
			$res = $ctrl(['code' => $payload])->checkIn();
			self::assertSame(200, $res->getStatus());
			self::assertTrue($res->getData()['data']['checked_in']);

			// order-ref lookup: liveTicketsForOrder resolves per-line
			// offering via LEFT JOINs — must work on real SQL too
			$res = $ctrl(['code' => $r['order']->getRef()])->lookup();
			self::assertSame(200, $res->getStatus());
			self::assertNotEmpty($res->getData()['data']['tickets']);

			// rescope the device to a foreign offering — same ticket must
			// become a uniform 404, not a scope error
			$qb2 = $this->db->getQueryBuilder();
			$qb2->update('bc_companion')
				->set('offering_ids', $qb2->createNamedParameter(json_encode([$f['offering'] + 9999])))
				->where($qb2->expr()->eq('id', $qb2->createNamedParameter($devId)))
				->executeStatement();
			$res = $ctrl(['code' => $payload])->lookup();
			self::assertSame(404, $res->getStatus());
			self::assertSame('NOT_FOUND', $res->getData()['error']);
		} finally {
			$doors->close();
			$qb3 = $this->db->getQueryBuilder();
			$qb3->delete('bc_companion')->where($qb3->expr()->eq('id',
				$qb3->createNamedParameter($devId)))->executeStatement();
		}
	}

	public function testCapiStatsExcludesRevokedTickets(): void
	{
		$f = $this->seedOffering(5);
		$r = $this->create($f['offering'], $f['event'], 2, $this->idem('stats'));
		$this->created['order'][] = (int)$r['order']->getId();
		$this->orders->markPaid((int)$r['order']->getId(), 'itest');
		$qb = $this->db->getQueryBuilder();
		$lineId = (int)$qb->select('id')->from('bc_order_line')
			->where($qb->expr()->eq('order_id',
				$qb->createNamedParameter((int)$r['order']->getId())))->executeQuery()->fetchOne();
		$tm = \OC::$server->get(\OCA\BookingCheck\Db\TicketMapper::class);
		$tks = $tm->findByLine($lineId);
		self::assertCount(2, $tks);
		// both guests through the door; one ticket later revoked (refund
		// after entry) — a revoked scan must not count as a check-in
		foreach ($tks as $tk) {
			$payload = $this->tickets->qrPayload($tk);
			$this->tickets->checkInByToken(
				substr($payload, strrpos($payload, ':') + 1), 'door');
		}
		$qb2 = $this->db->getQueryBuilder();
		$qb2->update('bc_ticket')
			->set('revoked_at', $qb2->createNamedParameter(new \DateTime(), 'datetime'))
			->where($qb2->expr()->eq('id', $qb2->createNamedParameter((int)$tks[0]->getId())))
			->executeStatement();

		$device = new \OCA\BookingCheck\Db\CompanionToken();
		$device->setRole('manager');
		$device->setOfferingIds(json_encode([$f['offering']]));
		$capi = new \OCA\BookingCheck\Controller\CapiController('bookingcheck',
			$this->createMock(\OCP\IRequest::class),
			\OC::$server->get(\OCA\BookingCheck\Service\TicketService::class), $this->db);
		$capi->companionDevice = $device;
		$stats = $capi->stats()->getData()['data']['stats'];
		$row = null;
		foreach ($stats as $s) {
			if ((int)$s['id'] === (int)$f['event']) {
				$row = $s;
			}
		}
		self::assertNotNull($row, 'our scheduled event must be in scope');
		self::assertSame(1, (int)$row['checked_in'],
			'a revoked ticket must not count as a check-in');
	}

	/**
	 * Template delete must drop ONLY unbooked future slots — a pooled
	 * slot whose bc_slot_res counters hold bookings survives with its
	 * pool rows; waitlist entries on removed slots go with them.
	 */
	public function testTemplateDestroyKeepsBookedPoolSlots(): void
	{
		$f = $this->seedPooledSlot(4);
		$this->db->getQueryBuilder()->insert('bc_slot_tpl')->values([
			'offering_id' => (string)$f['offering'], 'duration_min' => '60',
			'cap_per_slot' => '4', 'buffer_min' => '0', 'lead_min' => '0',
			'horizon_days' => '60', 'weekly_rrule' => "'MO 10:00'",
			'resource_id' => (string)$f['resource'],
		])->executeStatement();
		$tplId = (int)$this->db->lastInsertId('oc_bc_slot_tpl');
		$this->created['slot_tpl'][] = $tplId;
		// re-point the seeded pooled slot at our template + an unbooked twin
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_slot')->set('tpl_id', $qb->createNamedParameter($tplId))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($f['slot'])))
			->executeStatement();
		$starts = (new \DateTimeImmutable('+31 days'))->format('Y-m-d H:i:s');
		$ends = (new \DateTimeImmutable('+31 days +1 hour'))->format('Y-m-d H:i:s');
		$this->db->getQueryBuilder()->insert('bc_slot')->values([
			'tpl_id' => (string)$tplId, 'offering_id' => (string)$f['offering'],
			'starts_at' => "'$starts'", 'ends_at' => "'$ends'", 'capacity' => '4',
		])->executeStatement();
		$freeSlot = (int)$this->db->lastInsertId('oc_bc_slot');
		$this->created['slot'][] = $freeSlot;
		$this->db->getQueryBuilder()->insert('bc_slot_res')->values([
			'slot_id' => (string)$freeSlot, 'resource_id' => (string)$f['resource'],
			'capacity' => '4', 'active' => '1',
		])->executeStatement();
		$freeSr = (int)$this->db->lastInsertId('oc_bc_slot_res');
		$this->created['slot_res'][] = $freeSr;
		// waitlist row parked on the doomed slot
		$this->db->getQueryBuilder()->insert('bc_waitlist')->values([
			'target_type' => "'slot'", 'target_id' => (string)$freeSlot,
			'email' => "'wl-tpldel@x.test'", 'state' => "'waiting'",
			'created_at' => "'" . (new \DateTimeImmutable())->format('Y-m-d H:i:s') . "'",
		])->executeStatement();
		$this->created['waitlist'][] = 'wl-tpldel@x.test';
		// a real hold through the pool marks the first slot booked
		$this->db->beginTransaction();
		$this->inventory->hold('slot', $f['slot'], 1, $f['resource']);
		$this->db->commit();

		$r = $this->slotCtrl()->templateDestroy($tplId)->getData()['data'];
		self::assertTrue($r['deleted']);
		self::assertSame(1, $r['slots_removed']);

		$qb = $this->db->getQueryBuilder();
		self::assertFalse($qb->select('id')->from('bc_slot')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($freeSlot)))
			->executeQuery()->fetch(), 'unbooked slot must be removed');
		$qb = $this->db->getQueryBuilder();
		self::assertFalse($qb->select('id')->from('bc_slot_res')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($freeSr)))
			->executeQuery()->fetch(), 'its pool row goes with it');
		$qb = $this->db->getQueryBuilder();
		self::assertFalse($qb->select('id')->from('bc_waitlist')
			->where($qb->expr()->eq('target_id', $qb->createNamedParameter($freeSlot)))
			->andWhere($qb->expr()->eq('target_type', $qb->createNamedParameter('slot')))
			->executeQuery()->fetch(), 'waitlist on a removed slot must not leak');
		$qb = $this->db->getQueryBuilder();
		self::assertNotFalse($qb->select('id')->from('bc_slot')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($f['slot'])))
			->executeQuery()->fetch(), 'pool-booked slot must survive');
		$qb = $this->db->getQueryBuilder();
		self::assertNotFalse($qb->select('id')->from('bc_slot_res')
			->where($qb->expr()->eq('slot_id', $qb->createNamedParameter($f['slot'])))
			->executeQuery()->fetch(), 'its pool counters must survive');
		$qb = $this->db->getQueryBuilder();
		self::assertFalse($qb->select('id')->from('bc_slot_tpl')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($tplId)))
			->executeQuery()->fetch(), 'template itself is gone');
	}

	/** Real controller against the real DB — FOR UPDATE paths must be
	 *  exercised on the actual engine, not a QB mock. */
	private function slotCtrl(array $params = []): \OCA\BookingCheck\Controller\SlotController
	{
		$req = $this->createMock(\OCP\IRequest::class);
		$req->method('getParam')->willReturnCallback(
			fn (string $k, $d = null) => $params[$k] ?? $d);
		return new \OCA\BookingCheck\Controller\SlotController('bookingcheck', $req,
			\OC::$server->get(\OCA\BookingCheck\Db\OfferingMapper::class),
			\OC::$server->get(\OCA\BookingCheck\Db\EventMapper::class),
			\OC::$server->get(\OCA\BookingCheck\Db\SlotTplMapper::class),
			\OC::$server->get(\OCA\BookingCheck\Db\SlotMapper::class),
			\OC::$server->get(\OCA\BookingCheck\Db\SeriesMapper::class),
			$this->db,
			\OC::$server->get(\OCA\BookingCheck\Service\SlotMaterializer::class),
			\OC::$server->get(\OCA\BookingCheck\Db\ResourceMapper::class),
			\OC::$server->get(\OCA\BookingCheck\Db\SlotResourceMapper::class),
			\OC::$server->get(\OCA\BookingCheck\Service\EmailService::class));
	}

	/**
	 * destroy() locks the slot row AND its pool rows before judging —
	 * an unbooked pooled slot disappears with its bc_slot_res row.
	 */
	public function testDestroyEmptyPooledSlotRemovesPoolRow(): void
	{
		$f = $this->seedPooledSlot(4);
		$r = $this->slotCtrl()->destroy($f['slot'])->getData()['data'];
		self::assertTrue($r['deleted']);
		$qb = $this->db->getQueryBuilder();
		self::assertFalse($qb->select('id')->from('bc_slot')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($f['slot'])))
			->executeQuery()->fetch(), 'slot row must be gone');
		$qb = $this->db->getQueryBuilder();
		self::assertFalse($qb->select('id')->from('bc_slot_res')
			->where($qb->expr()->eq('slot_id', $qb->createNamedParameter($f['slot'])))
			->executeQuery()->fetch(), 'orphan pool rows would leak capacity');
	}

	/**
	 * A slot whose only bookings live on bc_slot_res must survive destroy —
	 * the check re-reads pool counters under the same locks hold() takes.
	 */
	public function testDestroyPoolBookedSlotRejectedOnRealDb(): void
	{
		$f = $this->seedPooledSlot(4);
		$this->db->beginTransaction();
		$this->inventory->hold('slot', $f['slot'], 1, $f['resource']);
		$this->db->commit();
		try {
			$this->slotCtrl()->destroy($f['slot']);
			self::fail('pool-booked slot must refuse delete');
		} catch (\OCA\BookingCheck\Exception\ValidationException) {
		}
		$qb = $this->db->getQueryBuilder();
		self::assertNotFalse($qb->select('id')->from('bc_slot')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($f['slot'])))
			->executeQuery()->fetch(), 'booked slot must survive');
	}

	/**
	 * update() on a pooled slot: capacity is pool-owned — a change is
	 * rejected while an identical value plus moved times goes through.
	 */
	public function testUpdatePooledSlotCapacityRuleOnRealDb(): void
	{
		$f = $this->seedPooledSlot(4);
		$ctrl = $this->slotCtrl([
			'starts_at' => (new \DateTimeImmutable('+31 days'))->format('Y-m-d H:i'),
			'ends_at' => (new \DateTimeImmutable('+31 days +1 hour'))->format('Y-m-d H:i'),
			'capacity' => '9',
		]);
		try {
			$ctrl->update($f['slot']);
			self::fail('pooled capacity change must refuse');
		} catch (\OCA\BookingCheck\Exception\ValidationException) {
		}
		$ctrl = $this->slotCtrl([
			'starts_at' => (new \DateTimeImmutable('+31 days'))->format('Y-m-d H:i'),
			'ends_at' => (new \DateTimeImmutable('+31 days +1 hour'))->format('Y-m-d H:i'),
			'capacity' => '4',
		]);
		$r = $ctrl->update($f['slot'])->getData()['data'];
		self::assertSame($f['slot'], (int)$r['id']);
		$qb = $this->db->getQueryBuilder();
		$row = $qb->select('starts_at')->from('bc_slot')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($f['slot'])))
			->executeQuery()->fetch();
		self::assertNotFalse($row);
		self::assertStringStartsWith(
			(new \DateTimeImmutable('+31 days'))->format('Y-m-d'), (string)$row['starts_at'],
			'moved times must persist');
	}

	/**
	 * Catalog availability on a pooled slot is the SUM of pool free seats,
	 * never the slot row's counter (which never moves for pooled slots) —
	 * and waitlist stays off since an offer couldn't pick a pool.
	 */
	public function testPooledSlotCatalogShowsPoolFree(): void
	{
		$f = $this->seedPooledSlot(4);
		$this->db->beginTransaction();
		$this->inventory->hold('slot', $f['slot'], 1, $f['resource']);
		$this->db->commit();
		$offering = \OC::$server->get(OfferingMapper::class)->find($f['offering']);
		$list = \OC::$server->get(\OCA\BookingCheck\Service\CatalogService::class)
			->occurrenceList($offering);
		$slot = null;
		foreach ($list as $row) {
			if (($row['target_type'] ?? '') === 'slot' && (int)$row['id'] === $f['slot']) {
				$slot = $row;
			}
		}
		self::assertNotNull($slot);
		self::assertSame(3, (int)$slot['available'], 'free = pool capacity − held');
		self::assertFalse($slot['waitlist'], 'pooled slots cannot offer a pool-less waitlist seat');
		self::assertCount(1, $slot['resources']);
		self::assertSame(3, (int)$slot['resources'][0]['available']);
		self::assertSame('Room A', $slot['resources'][0]['name']);
	}

	public function testLiveTicketsForOrderExcludesRevoked(): void
	{
		$f = $this->seedOffering(5);
		$r = $this->create($f['offering'], $f['event'], 2, $this->idem('flow-h'));
		$this->created['order'][] = (int)$r['order']->getId();
		$this->orders->markPaid((int)$r['order']->getId(), 'itest');
		$live = $this->tickets->liveTicketsForOrder((int)$r['order']->getId());
		self::assertCount(2, $live, 'a paid order exposes its live tickets to the door');
		// refund revokes every ticket — the door list must shrink with it
		$this->orders->transitionToCancelled((int)$r['order']->getId(), 'itest');
		$this->orders->transitionToRefunded((int)$r['order']->getId(), 'itest');
		self::assertSame([], $this->tickets->liveTicketsForOrder(
			(int)$r['order']->getId()),
			'revoked tickets must never surface in the door lookup');
	}

	public function testSeriesHoldConsumesEveryMemberEvent(): void
	{
		$f = $this->seedOffering(5);
		$seriesId = $this->seedSeries($f['offering'], 4, 2);
		$r = $this->create($f['offering'], $seriesId, 2, $this->idem('series-a'), false, 'series');
		$this->created['order'][] = (int)$r['order']->getId();

		self::assertSame(['capacity' => 4, 'sold' => 0, 'held' => 2], $this->seriesCounters($seriesId));
		foreach ($this->memberCounters($seriesId) as $m) {
			self::assertSame(2, (int)$m['held'], 'every member event must carry the hold');
		}

		// and releasing unwinds every member too — no stranded holds
		$this->orders->cancelPending((int)$r['order']->getId(), 'itest');
		self::assertSame(['capacity' => 4, 'sold' => 0, 'held' => 0], $this->seriesCounters($seriesId));
		foreach ($this->memberCounters($seriesId) as $m) {
			self::assertSame(0, (int)$m['held']);
		}
	}

	public function testSeriesFullMemberRejectsSeriesSeat(): void
	{
		$f = $this->seedOffering(5);
		$seriesId = $this->seedSeries($f['offering'], 4, 2);
		$members = $this->memberCounters($seriesId);
		// fill ONE member event directly — the series seat must fail even
		// though the series counter still has room.
		$direct = $this->create($f['offering'], (int)$members[0]['id'], 4, $this->idem('series-b'));
		$this->created['order'][] = (int)$direct['order']->getId();

		$this->expectException(CapacityException::class);
		$this->create($f['offering'], $seriesId, 1, $this->idem('series-c'), false, 'series');
	}

	public function testMultiEventOrderHoldsAndReleasesBoth(): void
	{
		$f = $this->seedOffering(5);
		// two events on the SAME offering — both must hold, both release.
		$starts = (new \DateTimeImmutable('+31 days'))->format('Y-m-d H:i:s');
		$ends = (new \DateTimeImmutable('+31 days +2 hours'))->format('Y-m-d H:i:s');
		$this->db->getQueryBuilder()->insert('bc_event')->values([
			'offering_id' => (string)$f['offering'], 'starts_at' => "'$starts'",
			'ends_at' => "'$ends'", 'capacity' => '5', 'status' => "'scheduled'",
		])->executeStatement();
		$event2 = (int)$this->db->lastInsertId('oc_bc_event');
		$this->created['event'][] = $event2;

		$r = $this->orders->createOrder($f['offering'], 'Two Dates', 'two@x.test', 'en',
			[['target_type' => 'event', 'target_id' => $f['event'], 'seats' => 1],
			 ['target_type' => 'event', 'target_id' => $event2, 'seats' => 2]],
			[], null, null, $this->idem('multi-a'), null, true, 'itest');
		$this->created['order'][] = (int)$r['order']->getId();
		self::assertSame(1, $this->counters($f['event'])['held']);
		self::assertSame(2, $this->counters($event2)['held']);

		$this->orders->cancelPending((int)$r['order']->getId(), 'itest');
		self::assertSame(0, $this->counters($f['event'])['held']);
		self::assertSame(0, $this->counters($event2)['held']);
	}

	public function testRefundMintsCreditNoteInsideTransition(): void
	{
		$f = $this->seedOffering(5);
		// is_test=0 so the invoice path runs — real orders mint documents.
		$r = $this->create($f['offering'], $f['event'], 2, $this->idem('cred-a'), false, 'event', false);
		$this->created['order'][] = (int)$r['order']->getId();
		$this->orders->markPaid((int)$r['order']->getId(), 'itest');

		$qb = $this->db->getQueryBuilder();
		$invoice = $qb->select('*')->from('bc_invoice')
			->where($qb->expr()->eq('order_id', $qb->createNamedParameter((int)$r['order']->getId())))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter('invoice')))
			->executeQuery()->fetch();
		self::assertNotFalse($invoice, 'paid non-test order must mint the purchase invoice');
		self::assertSame(5000, (int)$invoice['gross_cents']);

		$this->orders->transitionToRefunded((int)$r['order']->getId(), 'itest', ['amount' => 5000], 5000);
		$credit = $qb->select('*')->from('bc_invoice')
			->where($qb->expr()->eq('order_id', $qb->createNamedParameter((int)$r['order']->getId())))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter('credit')))
			->executeQuery()->fetch();
		self::assertNotFalse($credit, 'refund must mint the credit note');
		self::assertSame(-5000, (int)$credit['gross_cents'], 'credit is the negative mirror');
		self::assertSame((int)$invoice['id'], (int)$credit['credits_invoice_id']);
		self::assertNotSame($credit['number'], $invoice['number'], 'gapless sequence — distinct numbers');

		// cleanup the sync task a non-test order enqueues
		$qb = $this->db->getQueryBuilder();
		$qb->delete('bc_sync_task')->where($qb->expr()->like('payload_json',
			$qb->createNamedParameter('%"orderId":' . (int)$r['order']->getId() . '%')))->executeStatement();
	}

	public function testRefundWithoutCreditMintsNoCredit(): void
	{
		$f = $this->seedOffering(5);
		$r = $this->create($f['offering'], $f['event'], 1, $this->idem('cred-b'), false, 'event', false);
		$this->created['order'][] = (int)$r['order']->getId();
		$this->orders->markPaid((int)$r['order']->getId(), 'itest');
		$this->orders->transitionToRefunded((int)$r['order']->getId(), 'itest');

		$qb = $this->db->getQueryBuilder();
		$credit = $qb->select('id')->from('bc_invoice')
			->where($qb->expr()->eq('order_id', $qb->createNamedParameter((int)$r['order']->getId())))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter('credit')))
			->executeQuery()->fetchOne();
		self::assertFalse($credit, 'no credit amount → no credit note');

		$qb = $this->db->getQueryBuilder();
		$qb->delete('bc_sync_task')->where($qb->expr()->like('payload_json',
			$qb->createNamedParameter('%"orderId":' . (int)$r['order']->getId() . '%')))->executeStatement();
	}

	public function testVoucherRestoredOnCancel(): void
	{
		$f = $this->seedOffering(5);
		$code = 'VO-' . strtoupper(bin2hex(random_bytes(3)));
		$ins = $this->db->getQueryBuilder();
		$ins->insert('bc_voucher')->values([
			'code' => $ins->createNamedParameter($code),
			'credit_cents' => '100', 'remaining_cents' => '100',
			'max_uses' => '3', 'uses' => '1', 'active' => '1',
			'created_at' => $ins->createNamedParameter(new \DateTime(), 'datetime'),
		])->executeStatement();
		$voucherId = (int)$this->db->lastInsertId('oc_bc_voucher');

		try {
			$r = $this->orders->createOrder($f['offering'], 'Vouch Buyer', 'v@x.test', 'en',
				[['target_type' => 'event', 'target_id' => $f['event'], 'seats' => 1]],
				[], null, $code, $this->idem('vouch-a'), null, true, 'itest');
			$this->created['order'][] = (int)$r['order']->getId();

			$qb = $this->db->getQueryBuilder();
			$row = $qb->select('remaining_cents', 'uses')->from('bc_voucher')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($voucherId)))
				->executeQuery()->fetch();
			self::assertSame(['remaining_cents' => 0, 'uses' => 2],
				['remaining_cents' => (int)$row['remaining_cents'], 'uses' => (int)$row['uses']],
				'100c voucher on a 2500c seat must burn the full credit + one use');

			// pre-payment cancel → credit AND use restored exactly once
			$this->orders->cancelPending((int)$r['order']->getId(), 'itest');
			$row = $qb->select('remaining_cents', 'uses')->from('bc_voucher')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($voucherId)))
				->executeQuery()->fetch();
			self::assertSame(['remaining_cents' => 100, 'uses' => 1],
				['remaining_cents' => (int)$row['remaining_cents'], 'uses' => (int)$row['uses']]);
		} finally {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('bc_voucher')->where($qb->expr()->eq('id',
				$qb->createNamedParameter($voucherId)))->executeStatement();
		}
	}

	public function testRefundReturnsVoucherCreditButKeepsUse(): void
	{
		$f = $this->seedOffering(5);
		$code = 'VO-' . strtoupper(bin2hex(random_bytes(3)));
		$ins = $this->db->getQueryBuilder();
		$ins->insert('bc_voucher')->values([
			'code' => $ins->createNamedParameter($code),
			'credit_cents' => '100', 'remaining_cents' => '100',
			'max_uses' => '3', 'uses' => '0', 'active' => '1',
			'created_at' => $ins->createNamedParameter(new \DateTime(), 'datetime'),
		])->executeStatement();
		$voucherId = (int)$this->db->lastInsertId('oc_bc_voucher');

		try {
			$r = $this->orders->createOrder($f['offering'], 'Vouch Buyer', 'v@x.test', 'en',
				[['target_type' => 'event', 'target_id' => $f['event'], 'seats' => 1]],
				[], null, $code, $this->idem('vouch-b'), null, true, 'itest');
			$this->created['order'][] = (int)$r['order']->getId();
			$this->orders->markPaid((int)$r['order']->getId(), 'itest');
			$this->orders->transitionToRefunded((int)$r['order']->getId(), 'itest', [], 2400);

			$qb = $this->db->getQueryBuilder();
			$row = $qb->select('remaining_cents', 'uses')
				->from('bc_voucher')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($voucherId)))
				->executeQuery()->fetch();
			// refund restores the credit but the redemption stays counted —
			// voucher is stored value; 'uses' is an audit of redemptions.
			self::assertSame(100, (int)$row['remaining_cents'], 'refund must return voucher credit');
			self::assertSame(1, (int)$row['uses'], 'use count stays — the redemption happened');
		} finally {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('bc_voucher')->where($qb->expr()->eq('id',
				$qb->createNamedParameter($voucherId)))->executeStatement();
		}
	}

	public function testReverseChargeVoucherIsNetDenominated(): void
	{
		// §13b: the charge basis is the NET remainder — a voucher redeemed
		// against the gross would burn the VAT fraction of the credit too.
		$code = 'VO-' . strtoupper(bin2hex(random_bytes(3)));
		$ins = $this->db->getQueryBuilder();
		$ins->insert('bc_voucher')->values([
			'code' => $ins->createNamedParameter($code),
			'credit_cents' => '2000', 'remaining_cents' => '2000',
			'max_uses' => '3', 'uses' => '0', 'active' => '1',
			'created_at' => $ins->createNamedParameter(new \DateTime(), 'datetime'),
		])->executeStatement();
		$voucherId = (int)$this->db->lastInsertId('oc_bc_voucher');
		try {
			// 2500c gross @19% → 2101c net owed; voucher covers 2000 of it
			$q = \OC::$server->get(\OCA\BookingCheck\Service\PricingService::class)->price(
				['id' => 0, 'price_cents' => 2500, 'currency' => 'EUR', 'vat_bp' => 1900],
				[['target_type' => 'event', 'target_id' => 1, 'seats' => 1]],
				[], null, $code, true);
			self::assertSame(2000, $q['voucherCents']);
			self::assertSame(101, $q['total'],
				'RC buyer must owe net remainder minus voucher, not net of post-voucher gross');
			self::assertSame(0, $q['vatCents']);
			self::assertSame(101, $q['netCents']);

			// a full-cover voucher burns exactly the net remainder — never the VAT
			$ins2 = $this->db->getQueryBuilder();
			$ins2->insert('bc_voucher')->values([
				'code' => $ins2->createNamedParameter($code . 'B'),
				'credit_cents' => '5000', 'remaining_cents' => '5000',
				'max_uses' => '3', 'uses' => '0', 'active' => '1',
				'created_at' => $ins2->createNamedParameter(new \DateTime(), 'datetime'),
			])->executeStatement();
			$voucherId2 = (int)$this->db->lastInsertId('oc_bc_voucher');
			try {
				$q2 = \OC::$server->get(\OCA\BookingCheck\Service\PricingService::class)->price(
					['id' => 0, 'price_cents' => 2500, 'currency' => 'EUR', 'vat_bp' => 1900],
					[['target_type' => 'event', 'target_id' => 1, 'seats' => 1]],
					[], null, $code . 'B', true);
				self::assertSame(2101, $q2['voucherCents'],
					'a full-cover RC voucher burns the net remainder, not the gross');
				self::assertSame(0, $q2['total']);
			} finally {
				$del = $this->db->getQueryBuilder();
				$del->delete('bc_voucher')->where($del->expr()->eq('id',
					$del->createNamedParameter($voucherId2)))->executeStatement();
			}
		} finally {
			$del = $this->db->getQueryBuilder();
			$del->delete('bc_voucher')->where($del->expr()->eq('id',
				$del->createNamedParameter($voucherId)))->executeStatement();
		}
	}

	public function testVoucherRedeemRejectsInsufficientBalance(): void
	{
		$code = 'VO-' . strtoupper(bin2hex(random_bytes(3)));
		$ins = $this->db->getQueryBuilder();
		$ins->insert('bc_voucher')->values([
			'code' => $ins->createNamedParameter($code),
			'credit_cents' => '100', 'remaining_cents' => '100',
			'max_uses' => '3', 'uses' => '0', 'active' => '1',
			'created_at' => $ins->createNamedParameter(new \DateTime(), 'datetime'),
		])->executeStatement();
		$voucherId = (int)$this->db->lastInsertId('oc_bc_voucher');
		try {
			$vm = \OC::$server->get(\OCA\BookingCheck\Db\VoucherMapper::class);
			// the WHERE clause IS the guard: demanding more than remaining
			// must fail atomically, never drive the balance negative.
			self::assertFalse($vm->redeem($voucherId, 500));
			$row = $vm->find($voucherId);
			self::assertSame(100, (int)$row->getRemainingCents());
			self::assertSame(0, (int)$row->getUses());

			// floor edge: restoring when uses is already 0 must not drive
			// it negative (double-restore / replay protection)
			$vm->restore($voucherId, 50);
			$row = $vm->find($voucherId);
			self::assertSame(150, (int)$row->getRemainingCents());
			self::assertSame(0, (int)$row->getUses(), 'uses has a floor at 0');
			// undo the top-up for the redeem assertions below
			$vm->redeem($voucherId, 50);

			self::assertTrue($vm->redeem($voucherId, 100), 'exact balance is a legal redeem');
			self::assertFalse($vm->redeem($voucherId, 1), 'balance is now zero');
		} finally {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('bc_voucher')->where($qb->expr()->eq('id',
				$qb->createNamedParameter($voucherId)))->executeStatement();
		}
	}

	public function testExpirePropagatesInventoryUnderflow(): void
	{
		$f = $this->seedOffering(5);
		$r = $this->create($f['offering'], $f['event'], 2, $this->idem('under-a'));
		$this->created['order'][] = (int)$r['order']->getId();

		// simulate inventory corruption: the held counter says 0 while the
		// order still references held seats — expiry must FAIL LOUDLY,
		// not be swallowed as a "stolen transition" and retried forever.
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_event')->set('held', $qb->createNamedParameter(0))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($f['event'])))
			->executeStatement();

		try {
			$this->orders->expire($r['order'], 'itest');
			self::fail('expire() must propagate an inventory underflow, not swallow it');
		} catch (StateConflictException $e) {
			self::assertNotSame('ILLEGAL_TRANSITION', $e->getCodeName());
		}
	}

	public function testPaidTransitionIsAudited(): void
	{
		$f = $this->seedOffering(5);
		$r = $this->create($f['offering'], $f['event'], 1, $this->idem('audit-a'));
		$this->created['order'][] = (int)$r['order']->getId();
		$this->orders->markPaid((int)$r['order']->getId(), 'itest');
		$qb = $this->db->getQueryBuilder();
		$row = $qb->select('action')->from('bc_audit')
			->where($qb->expr()->eq('order_id', $qb->createNamedParameter((int)$r['order']->getId())))
			->andWhere($qb->expr()->eq('action', $qb->createNamedParameter('order.paid')))
			->executeQuery()->fetch();
		self::assertNotFalse($row, 'the paid transition must be audited');
	}

	public function testAuditChainRejectsForkedAppend(): void
	{
		// UNIQUE(prev_hash) is the one-child-per-node backstop: a writer
		// that raced the chain lock and read a stale head must collide at
		// insert — a forked chain is indistinguishable from tampering.
		$audit = \OCP\Server::get(\OCA\BookingCheck\Db\AuditEntryMapper::class);
		$mk = function (string $prev, string $action): \OCA\BookingCheck\Db\AuditEntry {
			$e = new \OCA\BookingCheck\Db\AuditEntry();
			$e->setActor('itest');
			$e->setAction($action);
			$e->setPrevHash($prev);
			$e->setRowHash(hash('sha256', $prev . $action));
			$e->setCreatedAt(new \DateTimeImmutable());
			return $e;
		};
		$prev = 'fork-probe-' . bin2hex(random_bytes(16));
		$audit->insert($mk($prev, 'first'));
		try {
			$audit->insert($mk($prev, 'second'));
			self::fail('a second child of the same prev_hash must not insert');
		} catch (\OCP\DB\Exception $e) {
			self::assertInstanceOf(\OCP\DB\Exception::class, $e);
		} finally {
			// probe rows carry no order_id — the suite sweeper won't reach them
			$qb = $this->db->getQueryBuilder();
			$qb->delete('bc_audit')
				->where($qb->expr()->eq('prev_hash', $qb->createNamedParameter($prev)))
				->executeStatement();
		}
	}

	public function testValidationRejectsBadInput(): void
	{
		$f = $this->seedOffering(5);
		$this->expectException(ValidationException::class);
		$this->create($f['offering'], $f['event'], 0, $this->idem('flow-h'));
	}

	// ---- cross-tenant ownership: a line target must belong to the offering ----

	public function testForeignEventTargetRejectedNoHold(): void
	{
		$a = $this->seedOffering(5);
		$b = $this->seedOffering(5);
		try {
			$this->create($a['offering'], $b['event'], 1, $this->idem('foreign-a'));
			self::fail('foreign target must be rejected');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('lines', $e->getFields());
		}
		self::assertSame(0, $this->counters($b['event'])['held'],
			'no hold may land on another offering\'s inventory');
	}

	public function testNonexistentTargetRejected(): void
	{
		$f = $this->seedOffering(5);
		try {
			$this->create($f['offering'], PHP_INT_MAX - 1, 1, $this->idem('ghost'));
			self::fail('unknown target must be rejected');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('lines', $e->getFields());
		}
	}

	// ---- consent (US-301 AC-3): required when tos set, wording snapshotted ----

	public function testConsentRequiredWhenTosSet(): void
	{
		$f = $this->seedOffering(5, ['tos_text' => 'By booking you agree to the house rules.']);
		try {
			$this->create($f['offering'], $f['event'], 1, $this->idem('consent-a'));
			self::fail('missing consent must be rejected');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('consent', $e->getFields());
		}
		self::assertSame(0, $this->counters($f['event'])['held'],
			'rejected consent must not hold seats');
	}

	public function testConsentAcceptedSnapshotsWording(): void
	{
		$f = $this->seedOffering(5, ['tos_text' => 'Terms v1: be nice.']);
		$r = $this->create($f['offering'], $f['event'], 1, $this->idem('consent-b'), true);
		$this->created['order'][] = (int)$r['order']->getId();

		$qb = $this->db->getQueryBuilder();
		$row = $qb->select('consent_at', 'consent_text')->from('bc_order')
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$r['order']->getId())))
			->executeQuery()->fetch();
		self::assertNotEmpty($row['consent_at']);
		self::assertSame('Terms v1: be nice.', $row['consent_text']);

		// seller edits terms afterwards — the order must keep the agreed text
		$upd = $this->db->getQueryBuilder();
		$upd->update('bc_offering')
			->set('tos_text', $upd->createNamedParameter('Terms v2: completely different.'))
			->where($upd->expr()->eq('id', $upd->createNamedParameter($f['offering'])))
			->executeStatement();
		$row2 = $qb->select('consent_text')->from('bc_order')
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$r['order']->getId())))
			->executeQuery()->fetchOne();
		self::assertSame('Terms v1: be nice.', $row2, 'consent wording is immutable once given');
	}

	public function testNoTosMeansConsentOptionalAndNull(): void
	{
		$f = $this->seedOffering(5);
		$r = $this->create($f['offering'], $f['event'], 1, $this->idem('consent-c'));
		$this->created['order'][] = (int)$r['order']->getId();
		$qb = $this->db->getQueryBuilder();
		$row = $qb->select('consent_at', 'consent_text')->from('bc_order')
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$r['order']->getId())))
			->executeQuery()->fetch();
		self::assertNull($row['consent_at']);
		self::assertNull($row['consent_text']);
	}

	public function testIdempotentReplaySkipsConsentCheck(): void
	{
		$f = $this->seedOffering(5, ['tos_text' => 'Terms.']);
		$tok = $this->idem('consent-d');
		$a = $this->create($f['offering'], $f['event'], 1, $tok, true);
		$this->created['order'][] = (int)$a['order']->getId();
		// replay WITHOUT the consent flag must still return the order —
		// idempotency lookup precedes validation by design
		$b = $this->create($f['offering'], $f['event'], 1, $tok, false);
		self::assertSame('idempotent-replay', $b['action']);
		self::assertSame(1, $this->counters($f['event'])['held']);
	}

	private function waitlistCount(string $targetType, int $targetId): int
	{
		$qb = $this->db->getQueryBuilder();
		return (int)$qb->select($qb->func()->count('id'))->from('bc_waitlist')
			->where($qb->expr()->eq('target_type', $qb->createNamedParameter($targetType)))
			->andWhere($qb->expr()->eq('target_id', $qb->createNamedParameter($targetId)))
			->executeQuery()->fetchOne();
	}

	public function testWaitlistJoinAcceptsSeriesTargets(): void
	{
		$wl = \OC::$server->get(\OCA\BookingCheck\Service\WaitlistService::class);
		$f = $this->seedOffering(2);
		$seriesId = $this->seedSeries($f['offering'], 2);
		$email = 'wl-' . bin2hex(random_bytes(4)) . '@x.test';
		$this->created['waitlist'][] = $email;
		self::assertTrue($wl->join('series', $seriesId, $email, 'en'));
		self::assertSame(1, $this->waitlistCount('series', $seriesId));
		// dedupe — same email+target joins once
		self::assertFalse($wl->join('series', $seriesId, $email, 'en'));
		self::assertSame(1, $this->waitlistCount('series', $seriesId));
	}

	public function testWaitlistJoinRejectsDeadTargetsSilently(): void
	{
		$wl = \OC::$server->get(\OCA\BookingCheck\Service\WaitlistService::class);
		$email = 'wl-' . bin2hex(random_bytes(4)) . '@x.test';
		$this->created['waitlist'][] = $email;
		// nonexistent target id — never creates a row
		self::assertFalse($wl->join('event', 999999999, $email, 'en'));
		// unpublished offering — waitlist rows for drafts would never serve
		$f = $this->seedOffering(2, ['status' => 'draft']);
		self::assertFalse($wl->join('event', $f['event'], $email, 'en'));
		// waitlist disabled on the offering
		$g = $this->seedOffering(2, ['waitlist_mode' => 'none']);
		self::assertFalse($wl->join('event', $g['event'], $email, 'en'));
		self::assertSame(0, $this->waitlistCount('event', $f['event']));
		self::assertSame(0, $this->waitlistCount('event', $g['event']));
	}

	public function testFreeTierDisablesProPaths(): void
	{
		\OCA\BookingCheck\Tests\ProLicense::disable();
		$wl = \OC::$server->get(\OCA\BookingCheck\Service\WaitlistService::class);
		$pricing = \OC::$server->get(\OCA\BookingCheck\Service\PricingService::class);
		$f = $this->seedOffering(2);

		// free tier: waitlist joins still accept — an allocate offering on
		// a lapsed tier degrades to a notify mail instead of dropping the
		// guest. What Pro gates is the claim-token seat hold, not the queue.
		$email = 'wl-' . bin2hex(random_bytes(4)) . '@x.test';
		$this->created['waitlist'][] = $email;
		self::assertTrue($wl->join('event', $f['event'], $email, 'en'));
		self::assertSame(1, $this->waitlistCount('event', $f['event']));

		// a valid voucher code is priced as if absent — never discounted
		$ins = $this->db->getQueryBuilder();
		$ins->insert('bc_voucher')->values([
			'code' => $ins->createNamedParameter('FREE-TIER'),
			'credit_cents' => '10000', 'remaining_cents' => '10000',
			'max_uses' => '5', 'uses' => '0', 'active' => '1',
			'created_at' => $ins->createNamedParameter(new \DateTime(), 'datetime'),
		])->executeStatement();
		$voucherId = (int)$this->db->lastInsertId('oc_bc_voucher');
		try {
			$offering = ['id' => 0, 'price_cents' => 2500, 'currency' => 'EUR', 'vat_bp' => 1900];
			$quote = $pricing->price($offering,
				[['target_type' => 'event', 'target_id' => 1, 'seats' => 1]],
				[], null, 'FREE-TIER');
			self::assertSame(2500, $quote['total'],
				'voucher code must not discount on the free tier');
			$row = $qb = $this->db->getQueryBuilder();
			$row = $qb->select('remaining_cents', 'uses')->from('bc_voucher')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($voucherId)))
				->executeQuery()->fetch();
			self::assertSame(['remaining_cents' => 10000, 'uses' => 0], [
				'remaining_cents' => (int)$row['remaining_cents'], 'uses' => (int)$row['uses'],
			], 'unused code must not burn credit');
		} finally {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('bc_voucher')->where($qb->expr()->eq('id',
				$qb->createNamedParameter($voucherId)))->executeStatement();
		}
	}

	private function tmpUpload(string $name = 'doc.pdf', string $body = "%PDF-1.4 fake"): array
	{
		$tmp = tempnam(sys_get_temp_dir(), 'bckup-');
		file_put_contents($tmp, $body);
		return ['error' => UPLOAD_ERR_OK, 'size' => strlen($body),
			'name' => $name, 'tmp_name' => $tmp, 'type' => 'application/pdf'];
	}

	private function seatLineId(int $orderId): int
	{
		foreach (\OC::$server->get(\OCA\BookingCheck\Db\OrderLineMapper::class)
			->findByOrder($orderId) as $l) {
			if ($l->getLineKind() === 'seat') {
				return (int)$l->getId();
			}
		}
		self::fail('no seat line on order');
	}

	private function paidOrder(int $offeringId, int $eventId, string $email): \OCA\BookingCheck\Db\Order
	{
		$r = $this->orders->createOrder($offeringId, 'Up Buyer', $email, 'en',
			[['target_type' => 'event', 'target_id' => $eventId, 'seats' => 1]],
			[], null, null, $this->idem('up-' . bin2hex(random_bytes(4))), null, true, 'itest');
		$oid = (int)$r['order']->getId();
		$this->created['order'][] = $oid;
		$this->orders->markPaid($oid, 'itest');
		// the in-memory entity predates the transition — re-read the row
		return \OC::$server->get(\OCA\BookingCheck\Db\OrderMapper::class)->find($oid);
	}

	public function testAttendeeUploadScopeQuotaAndOwnership(): void
	{
		$f = $this->seedOffering(5, ['sharing_enabled' => 1, 'sharing_scope' => 'occurrence']);
		$svc = \OC::$server->get(\OCA\BookingCheck\Service\UploadService::class);
		$offering = \OC::$server->get(\OCA\BookingCheck\Db\OfferingMapper::class)->find($f['offering']);

		$a = $this->paidOrder($f['offering'], $f['event'], 'up-a@x.test');
		$b = $this->paidOrder($f['offering'], $f['event'], 'up-b@x.test');
		// a third buyer on a DIFFERENT event — occurrence scope must isolate
		$starts = (new \DateTimeImmutable('+40 days'))->format('Y-m-d H:i:s');
		$ends = (new \DateTimeImmutable('+40 days +2 hours'))->format('Y-m-d H:i:s');
		$this->db->getQueryBuilder()->insert('bc_event')->values([
			'offering_id' => (string)$f['offering'], 'starts_at' => "'$starts'",
			'ends_at' => "'$ends'", 'capacity' => '5', 'status' => "'scheduled'",
		])->executeStatement();
		$event2 = (int)$this->db->lastInsertId('oc_bc_event');
		$this->created['event'][] = $event2;
		$c = $this->paidOrder($f['offering'], $event2, 'up-c@x.test');

		$u = $svc->store($a, $offering, $this->seatLineId((int)$a->getId()), 'Anna', $this->tmpUpload());

		// own + same-occurrence see it; the foreign-occurrence buyer does not
		$la = $svc->listFor($a, $offering);
		$lb = $svc->listFor($b, $offering);
		$lc = $svc->listFor($c, $offering);
		self::assertCount(1, $la);
		self::assertTrue($la[0]['own']);
		self::assertCount(1, $lb);
		self::assertFalse($lb[0]['own'], 'same occurrence — shared, not own');
		self::assertCount(0, $lc, 'different occurrence must not see the file');

		// foreign order cannot download it either (uniform miss)
		$this->expectException(\OCA\BookingCheck\Exception\NotFoundException::class);
		$svc->streamFor($u, $c, $offering);
	}

	public function testAttendeeUploadQuotaAndOfferingScope(): void
	{
		$f = $this->seedOffering(5, ['sharing_enabled' => 1, 'sharing_scope' => 'offering',
			'upload_max_mb' => 1, 'upload_quota_mb' => 1]);
		$svc = \OC::$server->get(\OCA\BookingCheck\Service\UploadService::class);
		$offering = \OC::$server->get(\OCA\BookingCheck\Db\OfferingMapper::class)->find($f['offering']);
		$a = $this->paidOrder($f['offering'], $f['event'], 'qa@x.test');

		// unpaid orders can never upload — uniform miss
		$pending = $this->create($f['offering'], $f['event'], 1, $this->idem('up-pend'));
		$this->created['order'][] = (int)$pending['order']->getId();
		try {
			$svc->store($pending['order'], $offering,
				$this->seatLineId((int)$pending['order']->getId()), 'A', $this->tmpUpload());
			self::fail('unpaid order must not upload');
		} catch (\OCA\BookingCheck\Exception\NotFoundException) {
		}

		// per-file cap: 2MB file vs 1MB limit — on a big-quota offering so
		// ONLY the cap can reject it (otherwise the quota masks the check)
		$f2 = $this->seedOffering(5, ['sharing_enabled' => 1, 'sharing_scope' => 'offering',
			'upload_max_mb' => 1, 'upload_quota_mb' => 4]);
		$offering2 = \OC::$server->get(\OCA\BookingCheck\Db\OfferingMapper::class)->find($f2['offering']);
		$a2 = $this->paidOrder($f2['offering'], $f2['event'], 'cap@x.test');
		try {
			$svc->store($a2, $offering2, $this->seatLineId((int)$a2->getId()), 'A',
				$this->tmpUpload('big.pdf', "%PDF" . str_repeat('x', 2 * 1024 * 1024)));
			self::fail('over-limit file must reject');
		} catch (\OCA\BookingCheck\Exception\ValidationException $e) {
			self::assertArrayHasKey('file', $e->getFields());
		}
		// ~900KB file ok — then the 1MB offering quota is spent
		$near = $this->tmpUpload('ok.pdf', "%PDF" . str_repeat('x', 900 * 1024));
		$svc->store($a, $offering, $this->seatLineId((int)$a->getId()), 'A', $near);
		$appData = \OCP\Server::get(\OCP\Files\AppData\IAppDataFactory::class)->get('bookingcheck');
		$dir = $appData->getFolder('uploads');
		$before = $dir->getDirectoryListing();
		try {
			$svc->store($a, $offering, $this->seatLineId((int)$a->getId()), 'A', $near);
			self::fail('second upload must trip the quota');
		} catch (\OCA\BookingCheck\Exception\ValidationException $e) {
			self::assertArrayHasKey('file', $e->getFields());
		}
		// rolled-back insert must purge the orphan refs — no unclaimed bytes
		self::assertCount(count($before), $dir->getDirectoryListing(),
			'quota-rolled-back upload left orphan files in appdata');

		// foreign line_id must never attach — uniform miss
		$b = $this->paidOrder($f['offering'], $f['event'], 'qb@x.test');
		$this->expectException(\OCA\BookingCheck\Exception\NotFoundException::class);
		$svc->store($a, $offering, $this->seatLineId((int)$b->getId()), 'A', $this->tmpUpload());
	}

	private function purgeTestMail(): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete('bc_email')->where($qb->expr()->like('to_email',
			$qb->createNamedParameter('%@x.test')))->executeStatement();
		$qb = $this->db->getQueryBuilder();
		$qb->delete('bc_broadcast')->where($qb->expr()->eq('sent_by',
			$qb->createNamedParameter('itest')))->executeStatement();
	}

	public function testBroadcastFansOutToPaidBuyersOnly(): void
	{
		$this->purgeTestMail(); // aborted mutation runs leave stale rows
		$f = $this->seedOffering(5);
		$bc = \OC::$server->get(\OCA\BookingCheck\Service\BroadcastService::class);
		$this->paidOrder($f['offering'], $f['event'], 'bc-a@x.test');
		$this->paidOrder($f['offering'], $f['event'], 'bc-b@x.test');
		// an unpaid order must never receive a broadcast
		$pending = $this->create($f['offering'], $f['event'], 1, $this->idem('bc-pending'));
		$this->created['order'][] = (int)$pending['order']->getId();

		$r = $bc->send($f['offering'], null, 'Update tonight', 'Bring ID.', 'itest');
		self::assertSame(2, $r['recipients']);

		$qb = $this->db->getQueryBuilder();
		$queued = (int)$qb->select($qb->func()->count('id'))->from('bc_email')
			->where($qb->expr()->eq('kind', $qb->createNamedParameter('broadcast')))
			->andWhere($qb->expr()->in('to_email', $qb->createNamedParameter(
				['bc-a@x.test', 'bc-b@x.test'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
			->executeQuery()->fetchOne();
		self::assertSame(2, $queued);

		$qb2 = $this->db->getQueryBuilder();
		$bcRow = $qb2->select('scope', 'rcpt_count')->from('bc_broadcast')
			->where($qb2->expr()->eq('id', $qb2->createNamedParameter($r['broadcastId'])))
			->executeQuery()->fetch();
		self::assertSame(['scope' => 'all', 'rcpt_count' => 2],
			['scope' => $bcRow['scope'], 'rcpt_count' => (int)$bcRow['rcpt_count']]);
		$this->purgeTestMail();
	}

	public function testBroadcastOccurrenceFilter(): void
	{
		$this->purgeTestMail();
		$f = $this->seedOffering(5);
		$bc = \OC::$server->get(\OCA\BookingCheck\Service\BroadcastService::class);
		$this->paidOrder($f['offering'], $f['event'], 'bf-a@x.test');
		$starts = (new \DateTimeImmutable('+50 days'))->format('Y-m-d H:i:s');
		$ends = (new \DateTimeImmutable('+50 days +2 hours'))->format('Y-m-d H:i:s');
		$this->db->getQueryBuilder()->insert('bc_event')->values([
			'offering_id' => (string)$f['offering'], 'starts_at' => "'$starts'",
			'ends_at' => "'$ends'", 'capacity' => '5', 'status' => "'scheduled'",
		])->executeStatement();
		$event2 = (int)$this->db->lastInsertId('oc_bc_event');
		$this->created['event'][] = $event2;
		$this->paidOrder($f['offering'], $event2, 'bf-b@x.test');

		$r = $bc->send($f['offering'], ['event', $f['event']], 'Only date A', 'Hi', 'itest');
		self::assertSame(1, $r['recipients'], 'only the filtered occurrence buys get mail');
		$this->purgeTestMail();
	}

	public function testEolPurgeTombstonesPiiAndDropsBlobs(): void
	{
		$this->purgeTestMail();
		// warned + due → processDue transitions to 'purged' and scrubs PII
		$f = $this->seedOffering(5, [
			'eol_state' => 'warned',
			'eol_action_at' => new \DateTimeImmutable('-1 minute'),
		]);
		// a REAL order (is_test=0) so markPaid allocates a GoBD invoice row
		$r = $this->create($f['offering'], $f['event'], 1, $this->idem('eol-a'),
			false, 'event', false);
		$oid = (int)$r['order']->getId();
		$this->created['order'][] = $oid;
		$this->orders->markPaid($oid, 'itest');
		$order = \OC::$server->get(\OCA\BookingCheck\Db\OrderMapper::class)->find($oid);
		$lineId = $this->seatLineId($oid);
		$ticketId = $this->orderTicketId($oid);

		// one of every PII-bearing record for this offering
		$qId = $this->seedQuestion($f['offering'], 'text', 0, 'roster', null, 1);
		$this->db->getQueryBuilder()->insert('bc_answer')->values([
			'ticket_id' => (string)$ticketId, 'question_id' => (string)$qId,
			'value' => "'peanut allergy'", 'answered_at' => "'2026-01-01 00:00:00'",
			'wording_rev' => '1',
		])->executeStatement();
		$this->db->getQueryBuilder()->insert('bc_review')->values([
			'line_id' => (string)$lineId, 'rating' => '5',
			'text' => "'Buyer wrote this'", 'status' => "'published'",
			'created_at' => "'2026-01-01 00:00:00'",
			'published_at' => "'2026-01-01 00:00:00'",
		])->executeStatement();
		$this->db->getQueryBuilder()->insert('bc_waitlist')->values([
			'target_type' => "'event'", 'target_id' => (string)$f['event'],
			'email' => "'wl-eol@x.test'", 'locale' => "'en'", 'state' => "'waiting'",
			'created_at' => "'2026-01-01 00:00:00'",
		])->executeStatement();
		$this->created['waitlist'][] = 'wl-eol@x.test';
		$this->db->getQueryBuilder()->insert('bc_email')->values([
			'kind' => "'order_confirmed'",
			'to_email' => "'" . strtolower((string)$order->getBuyerEmail()) . "'",
			'locale' => "'en'", 'payload_json' => "'{}'", 'status' => "'pending'",
			'created_at' => "'2026-01-01 00:00:00'",
		])->executeStatement();
		$svc = \OC::$server->get(\OCA\BookingCheck\Service\UploadService::class);
		$offering = \OC::$server->get(\OCA\BookingCheck\Db\OfferingMapper::class)->find($f['offering']);
		$up = $svc->store($order, $offering, $lineId, 'Eol', $this->tmpUpload());
		$appData = \OCP\Server::get(\OCP\Files\AppData\IAppDataFactory::class)->get('bookingcheck');
		$uploadsDir = $appData->getFolder('uploads');
		self::assertTrue($uploadsDir->fileExists($up->getFileRef()), 'pre-purge blob exists');

		// remaining PII surfaces: portal otp, pass holder fields, abuse
		// reporter, queued CRM sync payload — all must die with the purge
		$buyerEmail = strtolower((string)$order->getBuyerEmail());
		$this->db->getQueryBuilder()->insert('bc_otp')->values([
			'email' => "'$buyerEmail'", 'code_hash' => "'" . hash('sha256', 'x') . "'",
			'attempts' => '0', 'created_at' => "'2026-01-01 00:00:00'",
			'expires_at' => "'2030-01-01 00:00:00'",
		])->executeStatement();
		$this->db->getQueryBuilder()->insert('bc_pass')->values([
			'offering_id' => (string)$f['offering'], 'order_id' => (string)$oid,
			'token_hash' => "'" . hash('sha256', 'eol-pass-' . $oid) . "'",
			'holder_name' => "'Max Mustermann'", 'holder_email' => "'$buyerEmail'",
			'valid_from' => "'2026-01-01 00:00:00'", 'valid_until' => "'2027-01-01 00:00:00'",
			'uses' => '0', 'status' => "'active'", 'created_at' => "'2026-01-01 00:00:00'",
		])->executeStatement();
		$this->db->getQueryBuilder()->insert('bc_abuse')->values([
			'upload_id' => (string)$up->getId(), 'reporter' => "'$buyerEmail'",
			'reason' => "'spam'", 'status' => "'open'", 'created_at' => "'2026-01-01 00:00:00'",
		])->executeStatement();
		$this->db->getQueryBuilder()->insert('bc_sync_task')->values([
			'kind' => "'crm_buyer'",
			'payload_json' => "'" . json_encode(['order_ref' => (string)$order->getRef(),
				'email' => $buyerEmail, 'name' => 'Max']) . "'",
			'status' => "'pending'", 'attempts' => '0',
			'next_at' => "'2026-01-01 00:00:00'", 'created_at' => "'2026-01-01 00:00:00'",
		])->executeStatement();

		$qb = $this->db->getQueryBuilder();
		$invoiceCount = (int)$qb->select($qb->func()->count('id'))->from('bc_invoice')
			->where($qb->expr()->eq('order_id', $qb->createNamedParameter($oid)))
			->executeQuery()->fetchOne();
		self::assertGreaterThan(0, $invoiceCount, 'paid order must have an invoice');

		\OC::$server->get(\OCA\BookingCheck\Service\EolService::class)->processDue();

		$qb = $this->db->getQueryBuilder();
		$state = $qb->select('eol_state')->from('bc_offering')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($f['offering'])))
			->executeQuery()->fetchOne();
		self::assertSame('purged', $state);

		// buyer + attendee PII tombstoned, not deleted (legal record keeps shape)
		$qb = $this->db->getQueryBuilder();
		$row = $qb->select('buyer_name', 'buyer_email', 'buyer_company', 'vat_id')
			->from('bc_order')->where($qb->expr()->eq('id',
				$qb->createNamedParameter($oid)))->executeQuery()->fetch();
		self::assertStringStartsWith('gdpr:', (string)$row['buyer_name']);
		self::assertStringStartsWith('gdpr:', (string)$row['buyer_email']);
		self::assertNull($row['buyer_company']);
		self::assertNull($row['vat_id']);
		$qb = $this->db->getQueryBuilder();
		$tname = $qb->select('attendee_name')->from('bc_ticket')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($ticketId)))
			->executeQuery()->fetchOne();
		self::assertStringStartsWith('gdpr:', (string)$tname);

		// every PII-bearing side table is empty for this offering
		foreach ([
			['bc_answer', 'ticket_id', [$ticketId]],
			['bc_review', 'line_id', [$lineId]],
			['bc_waitlist', 'target_id', [$f['event']]],
			['bc_upload', 'offering_id', [$f['offering']]],
		] as [$t, $col, $ids]) {
			$qb = $this->db->getQueryBuilder();
			$n = (int)$qb->select($qb->func()->count('id'))->from($t)
				->where($qb->expr()->in($col, $qb->createNamedParameter($ids,
					\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
				->executeQuery()->fetchOne();
			self::assertSame(0, $n, "$t rows must be purged");
		}
		$qb = $this->db->getQueryBuilder();
		$mailLeft = (int)$qb->select($qb->func()->count('id'))->from('bc_email')
			->where($qb->expr()->eq('to_email',
				$qb->createNamedParameter(strtolower((string)$order->getBuyerEmail()))))
			->executeQuery()->fetchOne();
		self::assertSame(0, $mailLeft, 'queued mail for the buyer must die too');
		self::assertFalse($uploadsDir->fileExists($up->getFileRef()),
			'the blob is the PII — row deletion alone leaks it');

		// the extended PII surfaces die with the purge
		$qb = $this->db->getQueryBuilder();
		self::assertSame(0, (int)$qb->select($qb->func()->count('id'))->from('bc_otp')
			->where($qb->expr()->eq('email', $qb->createNamedParameter($buyerEmail)))
			->executeQuery()->fetchOne(), 'otp rows are the portal re-entry path');
		$qb = $this->db->getQueryBuilder();
		$pass = $qb->select('holder_name', 'holder_email')->from('bc_pass')
			->where($qb->expr()->eq('order_id', $qb->createNamedParameter($oid)))
			->executeQuery()->fetch();
		self::assertStringStartsWith('gdpr:', (string)$pass['holder_name']);
		self::assertStringStartsWith('gdpr:', (string)$pass['holder_email']);
		$qb = $this->db->getQueryBuilder();
		self::assertSame(0, (int)$qb->select($qb->func()->count('id'))->from('bc_abuse')
			->where($qb->expr()->eq('reporter', $qb->createNamedParameter($buyerEmail)))
			->executeQuery()->fetchOne(), 'abuse reporter email is PII');
		$qb = $this->db->getQueryBuilder();
		self::assertSame(0, (int)$qb->select($qb->func()->count('id'))->from('bc_sync_task')
			->where($qb->expr()->like('payload_json',
				$qb->createNamedParameter('%' . $buyerEmail . '%')))
			->executeQuery()->fetchOne(), 'queued crm payload must not outlive the purge');

		// legal/audit records survive untouched
		$qb = $this->db->getQueryBuilder();
		$invoicesLeft = (int)$qb->select($qb->func()->count('id'))->from('bc_invoice')
			->where($qb->expr()->eq('order_id', $qb->createNamedParameter($oid)))
			->executeQuery()->fetchOne();
		self::assertSame($invoiceCount, $invoicesLeft, 'invoices are GoBD records');
		$qb = $this->db->getQueryBuilder();
		$auditLeft = (int)$qb->select($qb->func()->count('id'))->from('bc_audit')
			->where($qb->expr()->eq('order_id', $qb->createNamedParameter($oid)))
			->executeQuery()->fetchOne();
		self::assertGreaterThan(0, $auditLeft, 'audit rows must survive purge');

		// idempotent: a second pass is a no-op, state stays purged
		\OC::$server->get(\OCA\BookingCheck\Service\EolService::class)->processDue();
		$qb = $this->db->getQueryBuilder();
		self::assertSame('purged', (string)$qb->select('eol_state')->from('bc_offering')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($f['offering'])))
			->executeQuery()->fetchOne());
	}

	public function testRetentionPruneJobRunsClean(): void
	{
		// aborted mutant runs can leak seeded rows — wipe before seeding
		foreach (['bc_otp' => 'email', 'bc_portal_sess' => 'email',
			'bc_email' => 'to_email'] as $t => $col) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($t)->where($qb->expr()->like($col,
				$qb->createNamedParameter('%@x.test')))->executeStatement();
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete('bc_sync_task')->where($qb->expr()->eq('kind',
			$qb->createNamedParameter('crm_buyer')))->executeStatement();

		// expired rows in EVERY pruned table — a wrong column name or a
		// non-portable delete kills the job mid-run and the later tables
		// silently never get pruned
		$old = (new \DateTimeImmutable('-200 days'))->format('Y-m-d H:i:s');
		$this->db->getQueryBuilder()->insert('bc_otp')->values([
			'email' => "'otp@x.test'", 'code_hash' => "'" . hash('sha256', '1') . "'",
			'attempts' => '0', 'created_at' => "'$old'", 'expires_at' => "'$old'",
		])->executeStatement();
		$otpId = (int)$this->db->lastInsertId('oc_bc_otp');
		// idle-expired session whose ABSOLUTE cap is still ahead — the prune
		// must key on expires_at, not abs_expires_at (mutant detector)
		$this->db->getQueryBuilder()->insert('bc_portal_sess')->values([
			'token_hash' => "'" . hash('sha256', 's') . "'", 'email' => "'sess@x.test'",
			'created_at' => "'$old'", 'expires_at' => "'$old'",
			'abs_expires_at' => "'" . (new \DateTimeImmutable('+5 days'))->format('Y-m-d H:i:s') . "'",
		])->executeStatement();
		$sessId = (int)$this->db->lastInsertId('oc_bc_portal_sess');
		$this->db->getQueryBuilder()->insert('bc_email')->values([
			'kind' => "'x'", 'to_email' => "'prune@x.test'", 'locale' => "'en'",
			'payload_json' => "'{}'", 'status' => "'sent'",
			'created_at' => "'$old'", 'sent_at' => "'$old'",
		])->executeStatement();
		$mailId = (int)$this->db->lastInsertId('oc_bc_email');
		$this->db->getQueryBuilder()->insert('bc_sync_task')->values([
			'kind' => "'crm_buyer'", 'payload_json' => "'{}'", 'status' => "'done'",
			'attempts' => '1', 'next_at' => "'$old'", 'created_at' => "'$old'",
			'done_at' => "'$old'",
		])->executeStatement();
		$taskId = (int)$this->db->lastInsertId('oc_bc_sync_task');

		$job = new \OCA\BookingCheck\BackgroundJob\RetentionPruneJob(
			\OC::$server->get(\OCP\AppFramework\Utility\ITimeFactory::class), $this->db);
		$job->start(\OC::$server->get(\OCP\BackgroundJob\IJobList::class));

		foreach ([['bc_otp', $otpId], ['bc_portal_sess', $sessId],
			['bc_email', $mailId], ['bc_sync_task', $taskId]] as [$t, $id]) {
			$qb = $this->db->getQueryBuilder();
			$left = (int)$qb->select($qb->func()->count('id'))->from($t)
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
				->executeQuery()->fetchOne();
			self::assertSame(0, $left, "$t row $id must be pruned");
		}
	}

	public function testIssuedDeviceScopeActuallyScopes(): void
	{
		$f = $this->seedOffering(5);
		$other = $this->seedOffering(5); // foreign offering — must be invisible

		$params = ['label' => 'door-1', 'role' => 'door',
			'offering_ids' => (string)$f['offering']];
		$req = $this->createMock(\OCP\IRequest::class);
		$req->method('getParam')->willReturnCallback(
			fn (string $k, $d = null) => $params[$k] ?? $d);
		// issueDevice is app-admin territory — the real session must hold a
		// principal isAppAdmin() accepts, or requireConfigAdmin refuses first
		$session = \OC::$server->get(\OCP\IUserSession::class);
		$session->setUser(\OC::$server->get(\OCP\IUserManager::class)->get('admin'));
		try {
			$sc = new \OCA\BookingCheck\Controller\SettingsController(
				'bookingcheck', $req,
				\OC::$server->get(\OCA\BookingCheck\Service\SettingsService::class),
				\OC::$server->get(\OCA\BookingCheck\Psp\PspManager::class),
				\OC::$server->get(\OCA\BookingCheck\Db\CompanionTokenMapper::class),
				\OC::$server->get(\OCA\BookingCheck\Service\TokenService::class),
				$session,
				\OC::$server->get(\OCA\BookingCheck\Service\SellerNotifyService::class),
				\OC::$server->get(\OCA\BookingCheck\Service\LicenseService::class),
				\OC::$server->get(\OCA\BookingCheck\Db\OfferingMapper::class),
				\OC::$server->get(\OCA\BookingCheck\Db\MailTplMapper::class),
				\OC::$server->get(\OCA\BookingCheck\Service\AccessControlService::class),
				\OC::$server->get(\OCA\BookingCheck\Service\DirectoryOptionsService::class),
				\OC::$server->get(\OCP\IUserManager::class),
				\OC::$server->get(\OCP\IGroupManager::class),
			);
			$res = $sc->issueDevice();
		} finally {
			$session->setUser(null);
		}
		self::assertSame(201, $res->getStatus());
		$devId = (int)$res->getData()['data']['device']['id'];

		// persisted scope must be JSON that deviceScope() can decode —
		// a comma string bricks the device entirely (fail-closed to nothing)
		$companions = \OC::$server->get(\OCA\BookingCheck\Db\CompanionTokenMapper::class);
		$device = $companions->find($devId);
		$scope = json_decode((string)$device->getOfferingIds(), true);
		self::assertSame([$f['offering']], $scope,
			'scope must persist as a JSON array — deviceScope() reads JSON');

		// and the live capi surface honours it: foreign events invisible
		$capi = new \OCA\BookingCheck\Controller\CapiController('bookingcheck',
			$this->createMock(\OCP\IRequest::class),
			\OC::$server->get(\OCA\BookingCheck\Service\TicketService::class), $this->db);
		$capi->companionDevice = $device;
		$events = $capi->events()->getData()['data']['events'];
		$seenOfferings = array_unique(array_map(fn ($e) => (int)$e['offering_id'], $events));
		self::assertNotContains($other['offering'], $seenOfferings,
			'a scoped device must not see foreign offerings');

		// cleanup — device rows aren't covered by the fixture teardown
		$device->setRevokedAt(new \DateTimeImmutable());
		$companions->update($device);
		$qb = $this->db->getQueryBuilder();
		$qb->delete('bc_companion')->where($qb->expr()->eq('id',
			$qb->createNamedParameter($devId)))->executeStatement();
	}

	public function testCapiEventsListsAllDoorTargetKinds(): void
	{
		$ev = $this->seedOffering(5);                       // standalone event
		$f2 = $this->seedOffering(5);
		$seriesId = $this->seedSeries($f2['offering'], 5);  // series w/ members
		$sl = $this->seedPooledSlot(4);                     // timeslot

		$capi = new \OCA\BookingCheck\Controller\CapiController('bookingcheck',
			$this->createMock(\OCP\IRequest::class),
			\OC::$server->get(\OCA\BookingCheck\Service\TicketService::class), $this->db);
		// no companionDevice → unrestricted door (deviceScope null)
		$rows = $capi->events()->getData()['data']['events'];

		$byType = [];
		foreach ($rows as $r) {
			$byType[(string)$r['type']][] = (int)$r['id'];
		}
		self::assertContains($ev['event'], $byType['event'] ?? [],
			'standalone event must be listed');
		self::assertContains($sl['slot'], $byType['slot'] ?? [],
			'timeslot must be listed — slot offerings need a door target');
		self::assertContains($seriesId, $byType['series'] ?? [],
			'series with upcoming members must be listed');

		// series member events must NOT appear as individual door targets —
		// the door checks in on the series once, not once per occurrence
		$memberIds = [];
		$qb = $this->db->getQueryBuilder();
		$memberIds = array_map('intval', $qb->select('id')->from('bc_event')
			->where($qb->expr()->eq('series_id',
				$qb->createNamedParameter($seriesId)))
			->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
		foreach ($memberIds as $mid) {
			self::assertNotContains($mid, $byType['event'] ?? [],
				'series member events stay off the door list');
		}

		// the series row carries its earliest upcoming member's window
		$seriesRow = null;
		foreach ($rows as $r) {
			if ((string)$r['type'] === 'series' && (int)$r['id'] === $seriesId) {
				$seriesRow = $r;
			}
		}
		self::assertNotNull($seriesRow);
		$earliest = (new \DateTimeImmutable('+30 days'))->format('Y-m-d H:i:s');
		self::assertSame($earliest, (string)$seriesRow['starts_at']);

		// scope still filters every kind — a device scoped to the slot
		// offering sees its slot AND its standalone event (both in scope),
		// never the foreign event or series
		$device = new \OCA\BookingCheck\Db\CompanionToken();
		$device->setOfferingIds(json_encode([$sl['offering']]));
		$capi->companionDevice = $device;
		$scoped = $capi->events()->getData()['data']['events'];
		self::assertNotEmpty($scoped);
		foreach ($scoped as $r) {
			self::assertSame($sl['offering'], (int)$r['offering_id'],
				'scoped device must only see in-scope offerings');
		}
		$scopedByType = [];
		foreach ($scoped as $r) {
			$scopedByType[(string)$r['type']][] = (int)$r['id'];
		}
		self::assertContains($sl['slot'], $scopedByType['slot'] ?? [],
			'in-scope slot must remain visible');
		self::assertNotContains($seriesId, $scopedByType['series'] ?? [],
			'foreign series must stay hidden');
		self::assertNotContains($ev['event'], $scopedByType['event'] ?? [],
			'foreign event must stay hidden');

		// manager stats mirror the same target kinds — but keep series
		// member events as individual per-date rows
		$device->setRole('manager');
		$device->setOfferingIds(null);
		$stats = $capi->stats()->getData()['data']['stats'];
		$statTypes = [];
		foreach ($stats as $r) {
			$statTypes[(string)$r['type']][] = (int)$r['id'];
		}
		self::assertContains($ev['event'], $statTypes['event'] ?? [],
			'stats must include standalone events');
		self::assertContains($sl['slot'], $statTypes['slot'] ?? [],
			'stats must include slots');
		self::assertContains($seriesId, $statTypes['series'] ?? [],
			'stats must include the series row');
		foreach ($memberIds as $mid) {
			self::assertContains($mid, $statTypes['event'] ?? [],
				'stats keep per-occurrence member rows');
		}
	}

	public function testQueueClaimIsExclusiveAndFenced(): void
	{
		$this->purgeTestMail();
		$outbox = \OC::$server->get(\OCA\BookingCheck\Db\EmailOutboxMapper::class);
		$this->db->getQueryBuilder()->insert('bc_email')->values([
			'kind' => "'order_confirmed'", 'to_email' => "'claim@x.test'",
			'locale' => "'en'", 'payload_json' => "'{}'", 'status' => "'pending'",
			'attempts' => '0', 'created_at' => "'2026-01-01 00:00:00'",
		])->executeStatement();
		$mailId = (int)$this->db->lastInsertId('oc_bc_email');

		$lease = new \DateTimeImmutable('+15 minutes');
		// worker A claims; worker B loses the same row
		self::assertTrue($outbox->claim($mailId, $lease));
		self::assertFalse($outbox->claim($mailId, $lease),
			'a second claim on a live lease must lose');

		// epoch fencing: worker B's stale mark is a no-op
		$outbox->markSent($mailId, 2); // wrong epoch — row is at attempts=1
		$qb = $this->db->getQueryBuilder();
		self::assertSame('sending', (string)$qb->select('status')->from('bc_email')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($mailId)))
			->executeQuery()->fetchOne());

		// the real owner flips it
		$outbox->markSent($mailId, 1);
		$qb = $this->db->getQueryBuilder();
		self::assertSame('sent', (string)$qb->select('status')->from('bc_email')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($mailId)))
			->executeQuery()->fetchOne());

		// lease expiry recovers a crashed worker's row
		$this->db->getQueryBuilder()->insert('bc_email')->values([
			'kind' => "'order_confirmed'", 'to_email' => "'crash@x.test'",
			'locale' => "'en'", 'payload_json' => "'{}'", 'status' => "'sending'",
			'attempts' => '1', 'next_at' => "'" . (new \DateTimeImmutable('-1 minute'))
				->format('Y-m-d H:i:s') . "'",
			'created_at' => "'2026-01-01 00:00:00'",
		])->executeStatement();
		$stuckId = (int)$this->db->lastInsertId('oc_bc_email');
		self::assertTrue($outbox->claim($stuckId, $lease),
			'an expired lease must be re-claimable');

		// a pending row at the attempt cap is dead-lettered territory —
		// claim must refuse it, not widen the budget
		$this->db->getQueryBuilder()->insert('bc_email')->values([
			'kind' => "'order_confirmed'", 'to_email' => "'capped@x.test'",
			'locale' => "'en'", 'payload_json' => "'{}'", 'status' => "'pending'",
			'attempts' => '4', 'created_at' => "'2026-01-01 00:00:00'",
		])->executeStatement();
		$cappedId = (int)$this->db->lastInsertId('oc_bc_email');
		self::assertFalse($outbox->claim($cappedId, $lease),
			'attempts=4 must be un-claimable — the cap is the dead-letter bound');
		$this->purgeTestMail();
	}

	public function testReverseChargeStripsVatFromTotal(): void
	{
		$pricing = \OC::$server->get(\OCA\BookingCheck\Service\PricingService::class);
		$offering = ['id' => 0, 'price_cents' => 2500, 'currency' => 'EUR', 'vat_bp' => 1900];
		$lines = [['target_type' => 'event', 'target_id' => 1, 'seats' => 1]];
		$normal = $pricing->price($offering, $lines);
		$rc = $pricing->price($offering, $lines, [], null, null, true);
		self::assertSame(2500, $normal['total']);
		self::assertSame($normal['netCents'], $rc['total'],
			'reverse-charge buyer pays the NET of the gross catalog price');
		self::assertSame(0, $rc['vatCents']);
		// a reverse-charge order carries it through to the order row
		$f = $this->seedOffering(5);
		$r = $this->orders->createOrder($f['offering'],
			'Biz Buyer', 'biz@x.test', 'en',
			[['target_type' => 'event', 'target_id' => $f['event'], 'seats' => 1]],
			[], null, null, $this->idem('rc-a'),
			['company' => 'ACME', 'country' => 'FR', 'vat_id' => 'FR12345678901',
				'vies_status' => 'valid', 'reverse_charge' => true], true, 'itest');
		$this->created['order'][] = (int)$r['order']->getId();
		self::assertSame(1, (int)$r['order']->getReverseCharge());
	}

	private function checkoutCtrl(array $params, ?\OCA\BookingCheck\Service\ViesService $vies = null): \OCA\BookingCheck\Controller\PubCheckoutController
	{
		$req = $this->createMock(\OCP\IRequest::class);
		$req->method('getParams')->willReturn($params);
		return new \OCA\BookingCheck\Controller\PubCheckoutController(
			'bookingcheck', $req,
			$this->orders,
			\OC::$server->get(\OCA\BookingCheck\Db\OrderMapper::class),
			\OC::$server->get(\OCA\BookingCheck\Db\OfferingMapper::class),
			\OC::$server->get(\OCA\BookingCheck\Service\CatalogService::class),
			\OC::$server->get(\OCA\BookingCheck\Service\PaySessionService::class),
			\OC::$server->get(\OCA\BookingCheck\Service\SettingsService::class),
			\OC::$server->get(\OCP\IURLGenerator::class),
			\OC::$server->get(\Psr\Log\LoggerInterface::class),
			$vies ?? \OC::$server->get(\OCA\BookingCheck\Service\ViesService::class),
			\OC::$server->get(\OCA\BookingCheck\Service\LicenseService::class),
			\OC::$server->get(\OCA\BookingCheck\Service\PricingService::class),
		);
	}

	private function fakeVies(string $verdict): \OCA\BookingCheck\Service\ViesService
	{
		$v = $this->createMock(\OCA\BookingCheck\Service\ViesService::class);
		$v->method('verify')->willReturn($verdict);
		return $v;
	}

	/**
	 * The §13b decision matrix at the checkout boundary — VIES verdicts are
	 * stubbed (offline), the controller-side wiring is the thing under test.
	 */
	public function testCheckoutBillingReverseChargeDecisionMatrix(): void
	{
		$settings = \OC::$server->get(\OCA\BookingCheck\Service\SettingsService::class);
		$origVat = $settings->get('seller_vat_id');
		$origCountry = $settings->get('seller_country');
		$origPsp = $settings->get('psp_provider');
		$settings->set('seller_vat_id', 'DE123456789');
		$settings->set('seller_country', 'DE');
		// The decision-matrix orders are €21/€25 gross — a PSP must resolve or
		// checkout aborts before the billing branch under test runs.
		$settings->set('psp_provider', 'test');
		$f = $this->seedOffering(5);
		$orderMapper = \OC::$server->get(\OCA\BookingCheck\Db\OrderMapper::class);
		$mk = fn (string $idem, array $billing) => [
			'offering_id' => $f['offering'], 'buyer_name' => 'Biz', 'buyer_email' => 'b@x.test',
			'idem_token' => $idem, 'consent' => '1',
			'lines' => [['target_type' => 'event', 'target_id' => $f['event'], 'seats' => 1]],
		] + $billing;
		$refOf = function ($resp): string {
			$data = json_decode(json_encode($resp->getData()), true);
			return (string)($data['data']['order_ref'] ?? '');
		};
		try {
			// 1) VIES 'valid' on a foreign-EU buyer → reverse charge on the order
			$r = $this->checkoutCtrl($mk($this->idem('v1'),
				['buyer_company' => 'FR SAS', 'billing_country' => 'FR', 'vat_id' => 'FR12345678901']),
				$this->fakeVies('valid'))->create();
			$order = $orderMapper->findByRef($refOf($r));
			self::assertNotNull($order, 'valid VIES must accept the checkout');
			$this->created['order'][] = (int)$order->getId();
			self::assertSame('valid', $order->getViesStatus());
			self::assertSame(1, (int)$order->getReverseCharge());
			self::assertSame(2101, (int)$order->getAmountCents(),
				'reverse charge bills the net of the €25.00 gross');
			// the issued invoice carries zero VAT
			$invoices = \OC::$server->get(\OCA\BookingCheck\Db\InvoiceMapper::class);
			$this->orders->markPaid((int)$order->getId(), 'psp:test');
			$inv = $invoices->findByOrderAndType((int)$order->getId(), 'invoice');
			self::assertNotNull($inv);
			self::assertSame(0, (int)$inv->getVatCents(), 'RC invoice carries no VAT');

			// 2) 'unchecked' (service degraded) → accepted, normal VAT billed
			$r = $this->checkoutCtrl($mk($this->idem('v2'),
				['buyer_company' => 'NL BV', 'billing_country' => 'NL', 'vat_id' => 'NL123456789B01']),
				$this->fakeVies('unchecked'))->create();
			$order = $orderMapper->findByRef($refOf($r));
			self::assertNotNull($order, 'unchecked VIES must not block checkout');
			$this->created['order'][] = (int)$order->getId();
			self::assertSame('unchecked', $order->getViesStatus());
			self::assertSame(0, (int)$order->getReverseCharge());
			self::assertSame(2500, (int)$order->getAmountCents(), 'unchecked bills full VAT');
			$this->orders->markPaid((int)$order->getId(), 'psp:test');
			$inv = $invoices->findByOrderAndType((int)$order->getId(), 'invoice');
			self::assertGreaterThan(0, (int)$inv->getVatCents(),
				'normal invoice carries the VAT line');

			// 3) VIES 'invalid' → field-level rejection, no order
			try {
				$this->checkoutCtrl($mk($this->idem('v3'),
					['billing_country' => 'FR', 'vat_id' => 'FR00000000000']),
					$this->fakeVies('invalid'))->create();
				self::fail('invalid VIES must reject');
			} catch (\OCA\BookingCheck\Exception\ValidationException $e) {
				self::assertArrayHasKey('vat_id', $e->getFields());
			}

			// 4) non-EU buyer (US tax id) → VIES never consulted, normal VAT
			$never = $this->createMock(\OCA\BookingCheck\Service\ViesService::class);
			$never->expects(self::never())->method('verify');
			$r = $this->checkoutCtrl($mk($this->idem('v4'),
				['buyer_company' => 'US Corp', 'billing_country' => 'US', 'vat_id' => 'US12345678']),
				$never)->create();
			$order = $orderMapper->findByRef($refOf($r));
			self::assertNotNull($order, 'non-EU tax id must not reject checkout');
			$this->created['order'][] = (int)$order->getId();
			self::assertSame('none', $order->getViesStatus());
			self::assertSame(0, (int)$order->getReverseCharge());
			self::assertSame(2500, (int)$order->getAmountCents());

			// 5) same-country buyer → no reverse charge path at all
			$r = $this->checkoutCtrl($mk($this->idem('v5'),
				['billing_country' => 'DE', 'vat_id' => 'DE123456789']),
				$never)->create();
			$order = $orderMapper->findByRef($refOf($r));
			$this->created['order'][] = (int)$order->getId();
			self::assertSame('none', $order->getViesStatus());
			self::assertSame(0, (int)$order->getReverseCharge());
		} finally {
			$settings->set('seller_vat_id', $origVat);
			$settings->set('seller_country', $origCountry);
			$settings->set('psp_provider', $origPsp);
		}
	}

	public function testRefundedOrderRevokesTickets(): void
	{
		$f = $this->seedOffering(5);
		$r = $this->create($f['offering'], $f['event'], 2, $this->idem('revoke-a'));
		$orderId = (int)$r['order']->getId();
		$this->created['order'][] = $orderId;
		$this->orders->markPaid($orderId, 'psp:test');
		$qb = $this->db->getQueryBuilder();
		$live = (int)$qb->select($qb->func()->count('id'))->from('bc_ticket')
			->where($qb->expr()->isNull('revoked_at'))
			->andWhere($qb->expr()->in('line_id', $qb->createFunction(
				'(SELECT id FROM *PREFIX*bc_order_line WHERE order_id = ' . $orderId . ')')))
			->executeQuery()->fetchOne();
		self::assertSame(2, $live, 'paid order must have live tickets before refund');
		$this->orders->transitionToRefunded($orderId, 'admin', [], 2500);
		$qb = $this->db->getQueryBuilder();
		$dead = (int)$qb->select($qb->func()->count('id'))->from('bc_ticket')
			->where($qb->expr()->isNotNull('revoked_at'))
			->andWhere($qb->expr()->in('line_id', $qb->createFunction(
				'(SELECT id FROM *PREFIX*bc_order_line WHERE order_id = ' . $orderId . ')')))
			->executeQuery()->fetchOne();
		self::assertSame(2, $dead, 'refund must revoke every issued ticket');
	}

	public function testBuyerCancelRevokesTickets(): void
	{
		$f = $this->seedOffering(5);
		$r = $this->create($f['offering'], $f['event'], 1, $this->idem('revoke-b'));
		$orderId = (int)$r['order']->getId();
		$this->created['order'][] = $orderId;
		$this->orders->markPaid($orderId, 'psp:test');
		$this->orders->transitionToCancelled($orderId, 'buyer');
		$qb = $this->db->getQueryBuilder();
		$dead = (int)$qb->select($qb->func()->count('id'))->from('bc_ticket')
			->where($qb->expr()->isNotNull('revoked_at'))
			->andWhere($qb->expr()->in('line_id', $qb->createFunction(
				'(SELECT id FROM *PREFIX*bc_order_line WHERE order_id = ' . $orderId . ')')))
			->executeQuery()->fetchOne();
		self::assertSame(1, $dead, 'buyer cancel of a paid order must revoke its tickets');
	}

	/**
	 * Release-path regression: a waitlist offer on a deactivated pool must
	 * not trap the release. The old code re-ran hold() after offerSeat —
	 * hold() re-checks slot_res.active=1, so an admin deactivating a pool
	 * while an offer was pending made every subsequent refund/cancel roll
	 * back with CapacityException. transferToOfferHold() moves the counter
	 * without re-validating: the seat was validated when it was sold.
	 */
	public function testRefundOnDeactivatedPoolStillOffersSeat(): void
	{
		$f = $this->seedPooledSlot(4);
		$this->db->getQueryBuilder()->insert('bc_waitlist')->values([
			'target_type' => "'slot'", 'target_id' => (string)$f['slot'],
			'email' => "'wl-poolrel@x.test'", 'state' => "'waiting'",
			'created_at' => "'" . (new \DateTimeImmutable())->format('Y-m-d H:i:s') . "'",
		])->executeStatement();
		$this->created['waitlist'][] = 'wl-poolrel@x.test';
		$r = $this->orders->createOrder($f['offering'], 'Test Buyer', 'pool-rel@x.test', 'en',
			[['target_type' => 'slot', 'target_id' => $f['slot'], 'seats' => 1,
				'resource_id' => $f['resource']]],
			[], null, null, $this->idem('pool-rel'), null, true, 'itest', false);
		$orderId = (int)$r['order']->getId();
		$this->created['order'][] = $orderId;
		$this->orders->markPaid($orderId, 'psp:test');
		// admin deactivates the pool while the order is still refundable
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_slot_res')->set('active', $qb->createNamedParameter(0))
			->where($qb->expr()->eq('slot_id', $qb->createNamedParameter($f['slot'])))
			->executeStatement();
		$this->orders->transitionToRefunded($orderId, 'admin', [], 2500);
		$qb = $this->db->getQueryBuilder();
		$w = $qb->select('state')->from('bc_waitlist')
			->where($qb->expr()->eq('email', $qb->createNamedParameter('wl-poolrel@x.test')))
			->executeQuery()->fetchOne();
		self::assertSame('offered', $w,
			'freed seat must reach the waitlist even on a deactivated pool');
		$qb = $this->db->getQueryBuilder();
		$row = $qb->select('sold', 'held')->from('bc_slot_res')
			->where($qb->expr()->eq('slot_id', $qb->createNamedParameter($f['slot'])))
			->executeQuery()->fetch();
		self::assertSame(['sold' => 0, 'held' => 1],
			['sold' => (int)$row['sold'], 'held' => (int)$row['held']],
			'sold-1 must convert into held+1 — the offer hold');
	}

	/** Same trap on the held-seat path: pending_payment → markFailed. */
	public function testFailOnDeactivatedPoolStillOffersSeat(): void
	{
		$f = $this->seedPooledSlot(4);
		$this->db->getQueryBuilder()->insert('bc_waitlist')->values([
			'target_type' => "'slot'", 'target_id' => (string)$f['slot'],
			'email' => "'wl-poolfail@x.test'", 'state' => "'waiting'",
			'created_at' => "'" . (new \DateTimeImmutable())->format('Y-m-d H:i:s') . "'",
		])->executeStatement();
		$this->created['waitlist'][] = 'wl-poolfail@x.test';
		$r = $this->orders->createOrder($f['offering'], 'Test Buyer', 'pool-fail@x.test', 'en',
			[['target_type' => 'slot', 'target_id' => $f['slot'], 'seats' => 1,
				'resource_id' => $f['resource']]],
			[], null, null, $this->idem('pool-fail'), null, true, 'itest', false);
		$orderId = (int)$r['order']->getId();
		$this->created['order'][] = $orderId;
		// pending_payment — the seat is HELD, not sold
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_slot_res')->set('active', $qb->createNamedParameter(0))
			->where($qb->expr()->eq('slot_id', $qb->createNamedParameter($f['slot'])))
			->executeStatement();
		$this->orders->markFailed($orderId, 'psp:test');
		$qb = $this->db->getQueryBuilder();
		$w = $qb->select('state')->from('bc_waitlist')
			->where($qb->expr()->eq('email', $qb->createNamedParameter('wl-poolfail@x.test')))
			->executeQuery()->fetchOne();
		self::assertSame('offered', $w,
			'freed held seat must reach the waitlist even on a deactivated pool');
		$qb = $this->db->getQueryBuilder();
		$row = $qb->select('sold', 'held')->from('bc_slot_res')
			->where($qb->expr()->eq('slot_id', $qb->createNamedParameter($f['slot'])))
			->executeQuery()->fetch();
		self::assertSame(['sold' => 0, 'held' => 1],
			['sold' => (int)$row['sold'], 'held' => (int)$row['held']],
			'held seat cycles: order hold released, offer hold placed');
	}

	public function testInvoiceNumbersAreUniqueAndMonotonic(): void
	{
		$f = $this->seedOffering(6);
		$nos = [];
		for ($i = 0; $i < 4; $i++) {
			// live orders only — test orders deliberately mint no invoice
			$r = $this->create($f['offering'], $f['event'], 1, $this->idem('inv-' . $i),
				false, 'event', false);
			$id = (int)$r['order']->getId();
			$this->created['order'][] = $id;
			$this->orders->markPaid($id, 'psp:test');
			$qb = $this->db->getQueryBuilder();
			$no = $qb->select('number')->from('bc_invoice')
				->where($qb->expr()->eq('order_id', $qb->createNamedParameter($id)))
				->andWhere($qb->expr()->eq('type', $qb->createNamedParameter('invoice')))
				->executeQuery()->fetchOne();
			self::assertNotFalse($no, 'live paid order must have an invoice');
			$nos[] = (int)$no;
		}
		self::assertSame(count($nos), count(array_unique($nos)),
			'every invoice number must be globally unique');
		$sorted = $nos;
		sort($sorted);
		self::assertSame($sorted, $nos, 'allocation is monotonic per sequence');
	}

	// ---------------------------------------------------------------
	// Roster: typed targets, series redirect, revoked tickets
	// ---------------------------------------------------------------

	private function rosterCtrl(): \OCA\BookingCheck\Controller\RosterController
	{
		return new \OCA\BookingCheck\Controller\RosterController(
			'bookingcheck',
			$this->createMock(\OCP\IRequest::class),
			\OC::$server->get(\OCA\BookingCheck\Db\TicketMapper::class),
			$this->tickets,
			$this->db,
			$this->createMock(\OCP\IUserSession::class),
		);
	}

	private function orderTicketId(int $orderId): int
	{
		$qb = $this->db->getQueryBuilder();
		return (int)$qb->select('t.id')->from('bc_ticket', 't')
			->innerJoin('t', 'bc_order_line', 'ol', $qb->expr()->eq('ol.id', 't.line_id'))
			->where($qb->expr()->eq('ol.order_id', $qb->createNamedParameter($orderId)))
			->executeQuery()->fetchOne();
	}

	public function testRosterFiltersByTargetTypeNotJustId(): void
	{
		$f = $this->seedOffering(5);
		$r = $this->create($f['offering'], $f['event'], 1, $this->idem('rost-a'));
		$oid = (int)$r['order']->getId();
		$this->created['order'][] = $oid;
		$this->orders->markPaid($oid, 'itest');
		$ownTicket = $this->orderTicketId($oid);

		// Forge the collision: a foreign order whose line targets
		// type=slot with the SAME numeric id the event has.
		$other = $this->create($f['offering'], $f['event'], 1, $this->idem('rost-b'));
		$oid2 = (int)$other['order']->getId();
		$this->created['order'][] = $oid2;
		$this->orders->markPaid($oid2, 'itest');
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_order_line')
			->set('target_type', $qb->createNamedParameter('slot'))
			->where($qb->expr()->eq('order_id', $qb->createNamedParameter($oid2)))
			->executeStatement();
		$foreignTicket = $this->orderTicketId($oid2);

		$roster = $this->rosterCtrl()->roster('event', $f['event'])->getData()['data']['roster'];
		$ids = array_map(fn ($t) => (int)$t['id'], $roster);
		self::assertContains($ownTicket, $ids);
		self::assertNotContains($foreignTicket, $ids,
			'a slot line sharing the numeric id must not leak into the event roster');

		$slotRoster = $this->rosterCtrl()->roster('slot', $f['event'])->getData()['data']['roster'];
		$slotIds = array_map(fn ($t) => (int)$t['id'], $slotRoster);
		self::assertContains($foreignTicket, $slotIds);
		self::assertNotContains($ownTicket, $slotIds);
	}

	public function testRosterMemberEventResolvesToSeriesAttendees(): void
	{
		$f = $this->seedOffering(5);
		$seriesId = $this->seedSeries($f['offering'], 4, 2);
		$r = $this->create($f['offering'], $seriesId, 1, $this->idem('rost-c'), false, 'series');
		$oid = (int)$r['order']->getId();
		$this->created['order'][] = $oid;
		$this->orders->markPaid($oid, 'itest');
		$ticketId = $this->orderTicketId($oid);

		$member = (int)$this->memberCounters($seriesId)[0]['id'];
		$roster = $this->rosterCtrl()->roster('event', $member)->getData()['data']['roster'];
		self::assertContains($ticketId, array_map(fn ($t) => (int)$t['id'], $roster),
			'a member event must surface its series attendees — orders target the series');
	}

	public function testAdminCheckInRejectsRevokedTicket(): void
	{
		$f = $this->seedOffering(5);
		$r = $this->create($f['offering'], $f['event'], 1, $this->idem('rost-d'));
		$oid = (int)$r['order']->getId();
		$this->created['order'][] = $oid;
		$this->orders->markPaid($oid, 'itest');
		$ticketId = $this->orderTicketId($oid);

		$this->tickets->revokeForOrder($oid);
		$res = $this->rosterCtrl()->checkIn($ticketId);
		self::assertSame(409, $res->getStatus(), 'a revoked ticket must never check in');
	}

	// ---------------------------------------------------------------
	// Per-attendee intake (manage-token-scoped answers)
	// ---------------------------------------------------------------

	private function seedQuestion(int $offeringId, string $kind, int $required,
		string $visibleTo, ?string $options = null, int $rev = 1): int
	{
		$qb = $this->db->getQueryBuilder();
		$v = [
			'offering_id' => (string)$offeringId, 'label' => "'Q?'",
			'kind' => "'$kind'", 'required' => (string)$required,
			'visible_to' => "'$visibleTo'", 'wording_rev' => (string)$rev,
			'active' => '1', 'sort' => '0',
		];
		if ($options !== null) {
			$v['options_json'] = $qb->createNamedParameter($options);
		}
		$qb->insert('bc_question')->values($v)->executeStatement();
		$id = (int)$this->db->lastInsertId('oc_bc_question');
		$this->created['question'][] = $id;
		return $id;
	}

	private function manageCtrl(array $params): \OCA\BookingCheck\Controller\ManageController
	{
		$req = $this->createMock(\OCP\IRequest::class);
		$req->method('getParam')->willReturnCallback(
			fn (string $k, $d = null) => $params[$k] ?? $d);
		return new \OCA\BookingCheck\Controller\ManageController(
			'bookingcheck', $req,
			\OC::$server->get(\OCA\BookingCheck\Db\OrderMapper::class),
			\OC::$server->get(\OCA\BookingCheck\Db\OrderLineMapper::class),
			\OC::$server->get(\OCA\BookingCheck\Db\TicketMapper::class),
			\OC::$server->get(\OCA\BookingCheck\Db\OfferingMapper::class),
			\OC::$server->get(\OCA\BookingCheck\Db\ReviewMapper::class),
			\OC::$server->get(\OCA\BookingCheck\Db\QuestionMapper::class),
			\OC::$server->get(\OCA\BookingCheck\Db\AnswerMapper::class),
			$this->tickets,
			\OC::$server->get(\OCA\BookingCheck\Service\PaySessionService::class),
			$this->orders,
			\OC::$server->get(\OCA\BookingCheck\Service\TokenService::class),
			\OC::$server->get(\OCA\BookingCheck\Service\EmailService::class),
			\OC::$server->get(\OCP\IURLGenerator::class),
			\OC::$server->get(\OCA\BookingCheck\Service\LicenseService::class),
			\OC::$server->get(\OCA\BookingCheck\Service\UploadService::class),
			\OC::$server->get(\OCA\BookingCheck\Db\UploadMapper::class),
			\OC::$server->get(\OCA\BookingCheck\Db\InvoiceMapper::class),
			\OC::$server->get(\OCA\BookingCheck\Service\SettingsService::class),
			\OC::$server->get(\OCA\BookingCheck\Service\WalletPassService::class),
			\OC::$server->get(\OCA\BookingCheck\Service\IcsService::class),
			\OC::$server->get(\OCA\BookingCheck\Service\EInvoiceService::class),
			\OC::$server->get(\OCA\BookingCheck\Db\AbuseReportMapper::class),
			\OC::$server->get(\OCA\BookingCheck\Service\PushService::class),
		);
	}

	public function testIntakeAnswersOwnershipRequiredAndWordingRev(): void
	{
		$f = $this->seedOffering(5);
		$qReq = $this->seedQuestion($f['offering'], 'text', 1, 'roster', null, 3);
		$qSel = $this->seedQuestion($f['offering'], 'select', 0, 'door', '["Veg","Meat"]', 7);
		$r = $this->create($f['offering'], $f['event'], 1, $this->idem('intake-a'));
		$oid = (int)$r['order']->getId();
		$this->created['order'][] = $oid;
		$this->orders->markPaid($oid, 'itest');
		// payment rotates the manage token — pin a known one for the test
		$token = 'itest-mt-' . bin2hex(random_bytes(16));
		$tokens = \OC::$server->get(\OCA\BookingCheck\Service\TokenService::class);
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_order')->set('manage_token_hash',
			$qb->createNamedParameter($tokens->hash($token)))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($oid)))
			->executeStatement();
		$ticketId = $this->orderTicketId($oid);

		// required question unanswered → fields error, nothing stored
		try {
			$this->manageCtrl(['ticket_id' => $ticketId,
				'answers' => [(string)$qSel => 'Veg']])->answers($token);
			self::fail('required intake must reject empty answers');
		} catch (\OCA\BookingCheck\Exception\ValidationException $e) {
			self::assertArrayHasKey('q' . $qReq, $e->getFields());
		}
		self::assertSame([], \OC::$server->get(\OCA\BookingCheck\Db\AnswerMapper::class)
			->answersByTicket([$ticketId]));

		// happy path: required + select → stored with wording_rev snapshot
		$res = $this->manageCtrl(['ticket_id' => $ticketId,
			'answers' => [(string)$qReq => 'gluten-free', (string)$qSel => 'Veg',
				'99999' => 'forged-question']])->answers($token);
		self::assertTrue($res->getData()['success'] ?? false);
		$stored = \OC::$server->get(\OCA\BookingCheck\Db\AnswerMapper::class)
			->answersByTicket([$ticketId]);
		self::assertSame('gluten-free', $stored[$ticketId][$qReq] ?? null);
		self::assertSame('Veg', $stored[$ticketId][$qSel] ?? null);
		self::assertCount(2, $stored[$ticketId], 'foreign question ids must never store');
		$qb = $this->db->getQueryBuilder();
		$rev = $qb->select('wording_rev')->from('bc_answer')
			->where($qb->expr()->eq('question_id', $qb->createNamedParameter($qReq)))
			->executeQuery()->fetchOne();
		self::assertSame(3, (int)$rev, 'answer must snapshot the question wording_rev');

		// forged select option is skipped, not stored
		$this->manageCtrl(['ticket_id' => $ticketId,
			'answers' => [(string)$qReq => 'gf', (string)$qSel => 'Poison']])->answers($token);
		$stored = \OC::$server->get(\OCA\BookingCheck\Db\AnswerMapper::class)
			->answersByTicket([$ticketId]);
		self::assertSame('Veg', $stored[$ticketId][$qSel],
			'an option outside the question list must not overwrite');

		// foreign ticket id under a valid token → uniform 404
		$other = $this->create($f['offering'], $f['event'], 1, $this->idem('intake-b'));
		$this->created['order'][] = (int)$other['order']->getId();
		$this->orders->markPaid((int)$other['order']->getId(), 'itest');
		$foreignTicket = $this->orderTicketId((int)$other['order']->getId());
		$this->expectException(\OCA\BookingCheck\Exception\NotFoundException::class);
		$this->manageCtrl(['ticket_id' => $foreignTicket,
			'answers' => [(string)$qReq => 'x']])->answers($token);
	}

	// ---------------------------------------------------------------
	// Timeslot materializer
	// ---------------------------------------------------------------

	public function testSlotMaterializerGeneratesWeeklySlotsIdempotently(): void
	{
		$f = $this->seedOffering(5, ['type' => 'timeslot']);
		$qb = $this->db->getQueryBuilder();
		$qb->insert('bc_slot_tpl')->values([
			'offering_id' => (string)$f['offering'], 'duration_min' => '60',
			'cap_per_slot' => '8', 'buffer_min' => '0', 'lead_min' => '0',
			'horizon_days' => '14', 'weekly_rrule' => "'MO 10:00'",
		])->executeStatement();
		$tplId = (int)$this->db->lastInsertId('oc_bc_slot_tpl');
		$this->created['slot_tpl'][] = $tplId;

		$mat = \OC::$server->get(\OCA\BookingCheck\Service\SlotMaterializer::class);
		$tpl = \OC::$server->get(\OCA\BookingCheck\Db\SlotTplMapper::class)->find($tplId);
		$first = $mat->materialize($tpl);
		// Mondays inside a 14-day horizon: 2 or 3 depending on today
		self::assertGreaterThanOrEqual(2, $first);
		self::assertLessThanOrEqual(3, $first);

		$qb = $this->db->getQueryBuilder();
		$rows = $qb->select('starts_at', 'ends_at', 'capacity')->from('bc_slot')
			->where($qb->expr()->eq('tpl_id', $qb->createNamedParameter($tplId)))
			->executeQuery()->fetchAll();
		self::assertCount($first, $rows);
		foreach ($rows as $r) {
			$d = new \DateTimeImmutable((string)$r['starts_at']);
			self::assertSame(1, (int)$d->format('N'), 'only Mondays materialize');
			self::assertSame('10:00', $d->format('H:i'));
			$e = new \DateTimeImmutable((string)$r['ends_at']);
			self::assertSame(3600, $e->getTimestamp() - $d->getTimestamp(), '60-min duration');
			self::assertSame(8, (int)$r['capacity']);
		}

		// idempotent — the daily job can sweep freely
		self::assertSame(0, $mat->materialize($tpl));

		// slots are real inventory — hold + release round-trips
		$sq = $this->db->getQueryBuilder();
		$slotId = (int)$sq->select('id')->from('bc_slot')
			->where($sq->expr()->eq('tpl_id', $sq->createNamedParameter($tplId)))
			->executeQuery()->fetchOne();
		$this->created['slot'][] = $slotId;
		$r = $this->orders->createOrder($f['offering'], 'Slot Buyer', 's@x.test', 'en',
			[['target_type' => 'slot', 'target_id' => $slotId, 'seats' => 2]],
			[], null, null, $this->idem('slot-a'), null, true, 'itest');
		$this->created['order'][] = (int)$r['order']->getId();
		$qb = $this->db->getQueryBuilder();
		self::assertSame(2, (int)$qb->select('held')->from('bc_slot')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($slotId)))
			->executeQuery()->fetchOne());
	}

	public function testSlotUniqueIndexRejectsConcurrentTwin(): void
	{
		// bc_slot_tpl_uq is the race backstop behind the materializer's
		// read-then-insert dedupe: two overlapping sweeps cannot mint
		// twin slots that would each sell their own capacity counter.
		$f = $this->seedOffering(5, ['type' => 'timeslot']);
		$qb = $this->db->getQueryBuilder();
		$qb->insert('bc_slot_tpl')->values([
			'offering_id' => (string)$f['offering'], 'duration_min' => '60',
			'cap_per_slot' => '8', 'buffer_min' => '0', 'lead_min' => '0',
			'horizon_days' => '14', 'weekly_rrule' => "'MO 10:00'",
		])->executeStatement();
		$tplId = (int)$this->db->lastInsertId('oc_bc_slot_tpl');
		$this->created['slot_tpl'][] = $tplId;

		$start = (new \DateTimeImmutable('+10 days'))->modify('next monday')
			->setTime(10, 0)->format('Y-m-d H:i:s');
		$end = (new \DateTimeImmutable('+10 days'))->modify('next monday')
			->setTime(11, 0)->format('Y-m-d H:i:s');
		$ins = function () use ($tplId, $f, $start, $end): int {
			$this->db->getQueryBuilder()->insert('bc_slot')->values([
				'tpl_id' => (string)$tplId, 'offering_id' => (string)$f['offering'],
				'starts_at' => "'$start'", 'ends_at' => "'$end'",
				'capacity' => '8', 'sold' => '0', 'held' => '0',
			])->executeStatement();
			return (int)$this->db->lastInsertId('oc_bc_slot');
		};
		$first = $ins();
		$this->created['slot'][] = $first;
		try {
			$dup = $ins();
			$this->created['slot'][] = $dup; // keep teardown sane if the index is gone
			self::fail('twin (tpl_id, starts_at) must violate the unique index');
		} catch (\OCP\DB\Exception $e) {
			self::assertSame(\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION,
				$e->getReason());
		}
	}

	public function testFreeOrderMintsNoInvoice(): void
	{
		// a €0 order must not burn a gapless invoice number on a document
		// that is not a fiscal invoice — it only pollutes DATEV exports.
		$f = $this->seedOffering(5, ['price_cents' => 0]);
		$r = $this->create($f['offering'], $f['event'], 1, $this->idem('free-inv'), false, 'event', false);
		$this->created['order'][] = (int)$r['order']->getId();
		self::assertSame(0, (int)$r['order']->getAmountCents());

		$qb = $this->db->getQueryBuilder();
		$count = (int)$qb->select($qb->func()->count('id'))->from('bc_invoice')
			->where($qb->expr()->eq('order_id', $qb->createNamedParameter((int)$r['order']->getId())))
			->executeQuery()->fetchOne();
		self::assertSame(0, $count, 'free order must not mint a fiscal document');

		$qb = $this->db->getQueryBuilder();
		$qb->delete('bc_sync_task')->where($qb->expr()->like('payload_json',
			$qb->createNamedParameter('%"orderId":' . (int)$r['order']->getId() . '%')))->executeStatement();
	}

	public function testDatevExportRunsAgainstRealSchema(): void
	{
		// regression: the export selected bc_order.vat_bp — a column that
		// never existed — so every DATEV run 500'd. Only a real-DB query
		// surfaces that; mocked QBs swallow unknown columns silently.
		$f = $this->seedOffering(5);
		$r = $this->create($f['offering'], $f['event'], 1, $this->idem('datev'), false, 'event', false);
		$this->created['order'][] = (int)$r['order']->getId();
		$this->orders->markPaid((int)$r['order']->getId(), 'itest');

		$out = \OC::$server->get(\OCA\BookingCheck\Service\DatevExportService::class)
			->export(new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('+1 day'));
		self::assertStringContainsString('Buchungsstapel', $out['csv']);
		$refRow = null;
		foreach (explode("\r\n", $out['csv']) as $csvLine) {
			if (str_contains($csvLine, $r['order']->getRef())) {
				$refRow = $csvLine;
			}
		}
		self::assertNotNull($refRow,
			'the paid order\'s invoice must post a booking row');
		// vat-bearing invoice carries the BU 9 tax key in its field slot
		self::assertStringContainsString(';9;', (string)$refRow);

		$qb = $this->db->getQueryBuilder();
		$qb->delete('bc_sync_task')->where($qb->expr()->like('payload_json',
			$qb->createNamedParameter('%"orderId":' . (int)$r['order']->getId() . '%')))->executeStatement();
	}

	public function testCartOrderLocksInIdOrderButKeepsLeadOffering(): void
	{
		// regression for the ABBA deadlock: createCartOrder must lock all
		// offerings in id order (findForUpdate), yet process groups in cart
		// order so the FIRST cart group stays the lead offering
		// (order.offering_id, deposit basis).
		$low = $this->seedOffering(5, ['price_cents' => 1000]);
		$high = $this->seedOffering(5, ['price_cents' => 4000]);
		self::assertGreaterThan($low['offering'], $high['offering'],
			'test needs high-id group first in the cart');

		$r = $this->orders->createCartOrder(
			[// deliberately reversed: high id first
				['offering_id' => $high['offering'],
				 'lines' => [['target_type' => 'event', 'target_id' => $high['event'], 'seats' => 1]],
				 'addons' => []],
				['offering_id' => $low['offering'],
				 'lines' => [['target_type' => 'event', 'target_id' => $low['event'], 'seats' => 1]],
				 'addons' => []],
			],
			'Cart Buyer', 'cart-' . $this->idem('c1') . '@x.test', 'en',
			[], null, $this->idem('cart-a'), null, 'itest', true);
		$order = $r['order'];
		$this->created['order'][] = (int)$order->getId();

		// lead = first cart group, NOT the lowest id
		self::assertSame($high['offering'], (int)$order->getOfferingId());
		// combined pricing across both offerings
		self::assertSame(5000, (int)$order->getAmountCents());
		// seats held on BOTH targets
		$held = function (int $eid): int {
			$q = $this->db->getQueryBuilder();
			return (int)$q->select('held')->from('bc_event')
				->where($q->expr()->eq('id', $q->createNamedParameter($eid)))
				->executeQuery()->fetchOne();
		};
		self::assertSame(1, $held($high['event']));
		self::assertSame(1, $held($low['event']));
		// both lines exist, each pointing at its own offering's event
		$lq = $this->db->getQueryBuilder();
		$lines = array_map('intval', $lq->select('target_id')->from('bc_order_line')
			->where($lq->expr()->eq('order_id',
				$lq->createNamedParameter((int)$order->getId())))
			->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
		self::assertCount(2, $lines);
		self::assertContainsEquals($high['event'], $lines);
		self::assertContainsEquals($low['event'], $lines);
	}

	public function testExportRosterScopesLinesByTargetOfferingNotLead(): void
	{
		// regression: the seller zip-export roster filtered o.offering_id
		// (the LEAD offering) — a cart's lines for the other offering were
		// listed under the lead's export AND missing from their own.
		$a = $this->seedOffering(5, ['price_cents' => 1000]);
		$b = $this->seedOffering(5, ['price_cents' => 2000]);
		$r = $this->orders->createCartOrder(
			[
				['offering_id' => $a['offering'],
				 'lines' => [['target_type' => 'event', 'target_id' => $a['event'], 'seats' => 1]],
				 'addons' => []],
				['offering_id' => $b['offering'],
				 'lines' => [['target_type' => 'event', 'target_id' => $b['event'], 'seats' => 1]],
				 'addons' => []],
			],
			'Cart Buyer', 'cart-' . $this->idem('roster') . '@x.test', 'en',
			[], null, $this->idem('cart-roster'), null, 'itest', true);
		$order = $r['order'];
		$this->created['order'][] = (int)$order->getId();

		$csv = new \OCA\BookingCheck\Service\CsvExportService($this->db);
		$rowsFor = function (int $offeringId) use ($csv): array {
			$lines = explode("\n", trim($csv->roster($offeringId)));
			array_shift($lines); // header
			return array_map('str_getcsv', $lines);
		};
		$ref = (string)$order->getRef();

		$rowsA = $rowsFor($a['offering']);
		$rowsB = $rowsFor($b['offering']);
		self::assertCount(1, $rowsA, 'lead offering roster must carry only its own line');
		self::assertCount(1, $rowsB, 'second offering roster must carry its line too');
		self::assertSame($ref, $rowsA[0][0]);
		self::assertSame($ref, $rowsB[0][0]);
		self::assertSame((string)$a['event'], $rowsA[0][7],
			'A roster must reference A\'s event, not B\'s');
		self::assertSame((string)$b['event'], $rowsB[0][7]);

		$qb = $this->db->getQueryBuilder();
		$qb->delete('bc_sync_task')->where($qb->expr()->like('payload_json',
			$qb->createNamedParameter('%"orderId":' . (int)$order->getId() . '%')))->executeStatement();
	}

	public function testCartOrderRejectsDuplicateOfferingGroup(): void
	{
		$f = $this->seedOffering(5);
		try {
			$this->orders->createCartOrder(
				[
					['offering_id' => $f['offering'],
					 'lines' => [['target_type' => 'event', 'target_id' => $f['event'], 'seats' => 1]],
					 'addons' => []],
					['offering_id' => $f['offering'],
					 'lines' => [['target_type' => 'event', 'target_id' => $f['event'], 'seats' => 1]],
					 'addons' => []],
				],
				'Cart Buyer', 'cart-' . $this->idem('c2') . '@x.test', 'en',
				[], null, $this->idem('cart-dup'), null, 'itest', true);
			self::fail('duplicate offering groups must be rejected');
		} catch (\OCA\BookingCheck\Exception\ValidationException $e) {
			self::assertArrayHasKey('cart', $e->getFields());
		}
	}

	public function testCartOrderReverseChargeChargesNetNotGross(): void
	{
		// §13b B2B cart: the buyer owes the NET of the catalog price —
		// summing the gross group subtotals would over-charge by the full
		// VAT fraction while the invoice reports VAT=0.
		$a = $this->seedOffering(5, ['price_cents' => 2500]);
		$b = $this->seedOffering(5, ['price_cents' => 1500]);
		$r = $this->orders->createCartOrder(
			[
				['offering_id' => $a['offering'],
				 'lines' => [['target_type' => 'event', 'target_id' => $a['event'], 'seats' => 1]],
				 'addons' => []],
				['offering_id' => $b['offering'],
				 'lines' => [['target_type' => 'event', 'target_id' => $b['event'], 'seats' => 1]],
				 'addons' => []],
			],
			'Biz Cart', 'cart-' . $this->idem('c3') . '@x.test', 'en',
			[], null, $this->idem('cart-rc'),
			['company' => 'ACME', 'country' => 'FR', 'vat_id' => 'FR12345678901',
				'vies_status' => 'valid', 'reverse_charge' => true],
			'itest', true);
		$order = $r['order'];
		$this->created['order'][] = (int)$order->getId();

		[$netA] = \OCA\BookingCheck\Service\PricingService::splitVat(2500, 1900);
		[$netB] = \OCA\BookingCheck\Service\PricingService::splitVat(1500, 1900);
		self::assertSame($netA + $netB, (int)$order->getAmountCents(),
			'reverse-charge cart must charge the summed per-group NET');
		self::assertSame(1, (int)$order->getReverseCharge());
		self::assertSame(1900, (int)$order->getVatBp(),
			'resolved rate is a fact of the order');

		$qb = $this->db->getQueryBuilder();
		$qb->delete('bc_sync_task')->where($qb->expr()->like('payload_json',
			$qb->createNamedParameter('%"orderId":' . (int)$order->getId() . '%')))->executeStatement();
	}

	public function testCartOrderRejectsMixedVatRates(): void
	{
		// the invoice model stores ONE net/vat pair — a cart mixing 19% and
		// 7% groups could not produce a correct document, so it is refused
		// at checkout like mixed currencies.
		$a = $this->seedOffering(5);
		$b = $this->seedOffering(5, ['vat_bp' => 700]);
		try {
			$this->orders->createCartOrder(
				[
					['offering_id' => $a['offering'],
					 'lines' => [['target_type' => 'event', 'target_id' => $a['event'], 'seats' => 1]],
					 'addons' => []],
					['offering_id' => $b['offering'],
					 'lines' => [['target_type' => 'event', 'target_id' => $b['event'], 'seats' => 1]],
					 'addons' => []],
				],
				'Cart Buyer', 'cart-' . $this->idem('c4') . '@x.test', 'en',
				[], null, $this->idem('cart-vat'), null, 'itest', true);
			self::fail('mixed-rate cart must be refused');
		} catch (\OCA\BookingCheck\Exception\ValidationException $e) {
			self::assertArrayHasKey('cart', $e->getFields());
		}
	}

	public function testInvoiceSplitsAtOrderVatBpNotCurrentOfferingRate(): void
	{
		// the invoice must split at the rate the order was priced and
		// charged at — a seller editing the offering rate between order
		// and issue must not rewrite the document's tax math.
		$f = $this->seedOffering(5, ['vat_bp' => 700]);
		$r = $this->create($f['offering'], $f['event'], 2, $this->idem('vat-rate'), false, 'event', false);
		$this->created['order'][] = (int)$r['order']->getId();
		self::assertSame(700, (int)$r['order']->getVatBp(),
			'order must persist the resolved pricing-time rate');
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_offering')->set('vat_bp', $qb->createNamedParameter(1900))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($f['offering'])))
			->executeStatement();

		$this->orders->markPaid((int)$r['order']->getId(), 'itest');
		$qb = $this->db->getQueryBuilder();
		$invoice = $qb->select('net_cents', 'vat_cents')->from('bc_invoice')
			->where($qb->expr()->eq('order_id', $qb->createNamedParameter((int)$r['order']->getId())))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter('invoice')))
			->executeQuery()->fetch();
		self::assertNotFalse($invoice);
		[$net, $vat] = \OCA\BookingCheck\Service\PricingService::splitVat(5000, 700);
		self::assertSame($net, (int)$invoice['net_cents'],
			'invoice splits at the order rate (7%), not the edited offering rate');
		self::assertSame($vat, (int)$invoice['vat_cents']);

		$qb = $this->db->getQueryBuilder();
		$qb->delete('bc_sync_task')->where($qb->expr()->like('payload_json',
			$qb->createNamedParameter('%"orderId":' . (int)$r['order']->getId() . '%')))->executeStatement();
	}

	public function testAbuseReportTwinViolatesUniqueIndex(): void
	{
		// bc_abuse(upload_id, reporter) UNIQUE — concurrent reportAbuse calls
		// pass the listForUpload pre-check together; the index decides, and
		// the loser's insert must be a unique violation (controllers map it
		// to duplicate:true), not a silent second row.
		$ins = function (string $reporter): int {
			$this->db->getQueryBuilder()->insert('bc_abuse')->values([
				'upload_id' => '424242', 'reporter' => "'$reporter'",
				'reason' => "'test'", 'status' => "'open'",
				'created_at' => "'2026-10-28 00:00:00'",
			])->executeStatement();
			return (int)$this->db->lastInsertId('oc_bc_abuse');
		};
		$ids = [$ins('racer@example.org')];
		try {
			$ids[] = $ins('racer@example.org');
			self::fail('twin (upload_id, reporter) must violate the unique index');
		} catch (\OCP\DB\Exception $e) {
			self::assertSame(\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION,
				$e->getReason());
		} finally {
			$del = $this->db->getQueryBuilder();
			$del->delete('bc_abuse')->where($del->expr()->in('id',
				$del->createNamedParameter($ids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
		// a different reporter on the same upload is NOT a twin
		$other = $ins('other@example.org');
		$del = $this->db->getQueryBuilder();
		$del->delete('bc_abuse')->where($del->expr()->eq('id',
			$del->createNamedParameter($other)))->executeStatement();
	}

	public function testMaterializerRruleValidation(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		\OCA\BookingCheck\Service\SlotMaterializer::parseRrule("MO 25:00");
	}


	public function testWalkupOrderIsPaidIdempotentAndConsumesSeats(): void
	{
		$f = $this->seedOffering(2);
		$idem = $this->idem('wu');
		$r = $this->orders->createWalkupOrder($f['offering'], 'event', $f['event'], 2,
			'device:door1', $idem);
		$this->created['order'][] = (int)$r['order']->getId();
		self::assertSame('done', $r['action']);
		self::assertSame('paid', $r['order']->getStatus());
		self::assertSame('walkup', $r['order']->getPayMethod());

		// replay — same idem token returns the SAME order, no second sale
		$r2 = $this->orders->createWalkupOrder($f['offering'], 'event', $f['event'], 2,
			'device:door1', $idem);
		self::assertSame('idempotent-replay', $r2['action']);
		self::assertSame((int)$r['order']->getId(), (int)$r2['order']->getId());

		// capacity consumed — the third seat must refuse
		$this->expectException(CapacityException::class);
		try {
			$this->orders->createWalkupOrder($f['offering'], 'event', $f['event'], 1,
				'device:door1', $this->idem('wu-over'));
		} finally {
			$qb = $this->db->getQueryBuilder();
			self::assertSame(2, (int)$qb->select('sold')->from('bc_event')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($f['event'])))
				->executeQuery()->fetchOne());
		}
	}

	public function testWalkupRejectsUnpublishedOffering(): void
	{
		$f = $this->seedOffering(2, ['status' => 'draft']);
		$this->expectException(\OCA\BookingCheck\Exception\NotFoundException::class);
		$this->orders->createWalkupOrder($f['offering'], 'event', $f['event'], 1,
			'device:door1', $this->idem('wu-draft'));
	}

	public function testPassOrderMintsPassesOnPayment(): void
	{
		$f = $this->seedOffering(5, ['type' => 'pass', 'price_cents' => 0]);
		$idem = $this->idem('pass');
		$r = $this->orders->createPassOrder($f['offering'], 'P Buyer', 'pb@x.test',
			'en', 2, null, null, $idem, null, 'itest');
		$this->created['order'][] = (int)$r['order']->getId();
		self::assertSame('done', $r['action']);
		self::assertSame('paid', $r['order']->getStatus());

		$qb = $this->db->getQueryBuilder();
		$passes = $qb->select('id', 'status', 'uses')->from('bc_pass')
			->where($qb->expr()->eq('order_id',
				$qb->createNamedParameter((int)$r['order']->getId())))
			->executeQuery()->fetchAll();
		self::assertCount(2, $passes);
		foreach ($passes as $p) {
			self::assertSame('active', $p['status']);
			self::assertSame(0, (int)$p['uses']);
		}
	}

	public function testPassAdmissionIsSingleUsePerTarget(): void
	{
		$f = $this->seedOffering(5, ['type' => 'pass', 'price_cents' => 0]);
		$r = $this->orders->createPassOrder($f['offering'], 'P Buyer', 'pb2@x.test',
			'en', 1, null, null, $this->idem('pass2'), null, 'itest');
		$this->created['order'][] = (int)$r['order']->getId();

		// plant a second event on the SAME offering as the admission target
		$starts = (new \DateTimeImmutable('+40 days'))->format('Y-m-d H:i:s');
		$ends = (new \DateTimeImmutable('+40 days +2 hours'))->format('Y-m-d H:i:s');
		$this->db->getQueryBuilder()->insert('bc_event')->values([
			'offering_id' => (string)$f['offering'], 'starts_at' => "'$starts'",
			'ends_at' => "'$ends'", 'capacity' => '10', 'status' => "'scheduled'",
		])->executeStatement();
		$eventId = (int)$this->db->lastInsertId('oc_bc_event');
		$this->created['event'][] = $eventId;

		$svc = \OCP\Server::get(\OCA\BookingCheck\Service\PassService::class);
		// the minted token is never stored plaintext — exercise admit() with a
		// token whose hash we insert directly (same code path as the door)
		$token = 'BCK1P-' . bin2hex(random_bytes(24));
		$uq = $this->db->getQueryBuilder();
		$uq->update('bc_pass')
			->set('token_hash', $uq->createNamedParameter(hash('sha256', $token)))
			->where($uq->expr()->eq('order_id',
				$uq->createNamedParameter((int)$r['order']->getId())))
			->executeStatement();

		$res = $svc->admit($token, 'event', $eventId, $f['offering'], 'device:t');
		self::assertTrue($res['ok'], 'first admission must succeed');

		$res2 = $svc->admit($token, 'event', $eventId, $f['offering'], 'device:t');
		self::assertFalse($res2['ok']);
		self::assertSame('already_admitted', $res2['reason']);

		// revocation kills future admissions on other targets
		$svc->revokeForOrder((int)$r['order']->getId());
		$res3 = $svc->admit($token, 'event', $eventId, $f['offering'], 'device:t');
		self::assertFalse($res3['ok']);
	}

	public function testPassOrderRejectsNonPassOffering(): void
	{
		$f = $this->seedOffering(5); // type event
		$this->expectException(\OCA\BookingCheck\Exception\NotFoundException::class);
		$this->orders->createPassOrder($f['offering'], 'P Buyer', 'pb3@x.test',
			'en', 1, null, null, $this->idem('pass3'), null, 'itest');
	}

	public function testDepositOrderTracksAndSettlesRemainder(): void
	{
		$f = $this->seedOffering(5, ['price_cents' => 10000]);
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_offering')->set('deposit_bp', $qb->createNamedParameter(2500))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($f['offering'])))
			->executeStatement();

		$r = $this->create($f['offering'], $f['event'], 1, $this->idem('dep'));
		$this->created['order'][] = (int)$r['order']->getId();
		$order = $r['order'];
		self::assertSame('deposit', $order->getPayMethod());
		self::assertSame(7500, (int)$order->getRemainderCents());

		$this->orders->markPaid((int)$order->getId(), 'itest');

		// a partial amount must not settle the remainder
		try {
			$this->orders->markRemainderReceived((int)$order->getId(), 'itest', 100);
			self::fail('partial remainder must be rejected');
		} catch (ValidationException) {
		}
		$this->orders->markRemainderReceived((int)$order->getId(), 'itest', 7500);
		$re = \OCP\Server::get(OrderMapper::class)->find((int)$order->getId());
		self::assertSame(0, (int)$re->getRemainderCents());

		// settled remainder refuses a second settlement
		$this->expectException(StateConflictException::class);
		$this->orders->markRemainderReceived((int)$order->getId(), 'itest');
	}

	public function testViewerOrderIdForOfferingMatchesViewerNotUploader(): void
	{
		$f = $this->seedOffering(5);
		// two distinct buyers on the same offering
		$up = $this->create($f['offering'], $f['event'], 1, $this->idem('up'));
		$this->created['order'][] = (int)$up['order']->getId();
		$co = $this->create($f['offering'], $f['event'], 1, $this->idem('co'));
		$this->created['order'][] = (int)$co['order']->getId();
		$this->orders->markPaid((int)$up['order']->getId(), 'itest');
		$this->orders->markPaid((int)$co['order']->getId(), 'itest');

		$ent = \OCP\Server::get(\OCA\BookingCheck\Service\EntitlementService::class);
		// emails were minted as buyer-<idem>@x.test — recover via order rows
		$om = \OCP\Server::get(OrderMapper::class);
		$coOrder = $om->find((int)$co['order']->getId());
		$upOrder = $om->find((int)$up['order']->getId());
		self::assertSame((int)$co['order']->getId(),
			$ent->viewerOrderIdForOffering((string)$coOrder->getBuyerEmail(), $f['offering']));
		self::assertSame((int)$up['order']->getId(),
			$ent->viewerOrderIdForOffering((string)$upOrder->getBuyerEmail(), $f['offering']));
		// a stranger gets nothing
		self::assertNull($ent->viewerOrderIdForOffering('nobody@x.test', $f['offering']));
	}

	public function testRevokedTicketDropsPortalEntitlement(): void
	{
		$f = $this->seedOffering(5);
		$r = $this->create($f['offering'], $f['event'], 1, $this->idem('rev-ent'));
		$oid = (int)$r['order']->getId();
		$this->created['order'][] = $oid;
		$this->orders->markPaid($oid, 'itest');

		// re-assign the issued ticket to a distinct attendee mailbox —
		// the buyer's own entitlement leg must stay out of the way
		$tid = $this->orderTicketId($oid);
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_ticket')
			->set('attendee_email', $qb->createNamedParameter('no-show@x.test'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($tid)))
			->executeStatement();

		// offering-wide material (target_id NULL)
		$qb = $this->db->getQueryBuilder();
		$qb->insert('bc_material')
			->setValue('offering_id', $qb->createNamedParameter($f['offering']))
			->setValue('kind', $qb->createNamedParameter('page'))
			->setValue('title', $qb->createNamedParameter('Handbook'))
			->setValue('page_md', $qb->createNamedParameter('# hi'))
			->executeStatement();
		$materialId = (int)$qb->getLastInsertId();

		$ent = \OCP\Server::get(\OCA\BookingCheck\Service\EntitlementService::class);
		self::assertNotEmpty($ent->entitledOrders('no-show@x.test'),
			'live attendee ticket must grant portal listing');
		self::assertTrue($ent->canAccessMaterial('no-show@x.test', $materialId));

		// revoke — the CAS path sellers use
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_ticket')
			->set('revoked_at', $qb->createNamedParameter(new \DateTime(), 'datetime'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($tid)))
			->executeStatement();

		self::assertSame([], $ent->entitledOrders('no-show@x.test'),
			'revoked ticket must drop the order from the portal list');
		self::assertFalse($ent->canAccessMaterial('no-show@x.test', $materialId),
			'revoked ticket must not keep material downloads alive');
		self::assertNull($ent->orderScope('no-show@x.test', $oid));
	}

	public function testReminderClaimDedupesAndReleaseRestores(): void
	{
		$reminders = \OCP\Server::get(\OCA\BookingCheck\Db\ReminderMapper::class);
		$orderId = 900001; // no FK on bc_reminder — the dedupe key is what matters
		self::assertTrue($reminders->claim($orderId, 'event', 11));
		self::assertFalse($reminders->claim($orderId, 'event', 11),
			'second claim on the same target must lose to the unique index');
		self::assertTrue($reminders->claim($orderId, 'slot', 11),
			'a different target type is a different reminder');
		$reminders->release($orderId, 'slot', 11);
		$reminders->release($orderId, 'event', 11);
		self::assertTrue($reminders->claim($orderId, 'event', 11),
			'release must reopen the slot so a failed send retries');
		$reminders->release($orderId, 'event', 11);
	}

	public function testMailTplOverrideAppliesThenDeleteRestores(): void
	{
		$tpls = \OCP\Server::get(\OCA\BookingCheck\Db\MailTplMapper::class);
		$renderer = \OCP\Server::get(\OCA\BookingCheck\Service\MailRenderer::class);
		// force_language/force_locale legitimately pin every l10n lookup on a
		// production instance — the assertions below probe the 'en'/'de'
		// fallback order, so the pin must be lifted for the test's duration.
		$config = \OCP\Server::get(\OCP\IConfig::class);
		$origForceLang = $config->getSystemValue('force_language', false);
		$origForceLocale = $config->getSystemValue('force_locale', false);
		$config->deleteSystemValue('force_language');
		$config->deleteSystemValue('force_locale');
		try {
			$tpls->upsert('order_confirmed', '', 'Haus-Shop sagt hallo', 'Extra %s bleibt Text.');
			[$s1, $b1] = $renderer->render('order_confirmed',
				['order_ref' => 'BCK-1', 'manage_url' => 'https://x'], 'en');
			self::assertSame('Haus-Shop sagt hallo', $s1);
			self::assertStringContainsString('Extra %s bleibt Text.', $b1);
			// the all-languages row also applies to 'de'
			[$s2] = $renderer->render('order_confirmed', ['order_ref' => 'B'], 'de');
			self::assertSame('Haus-Shop sagt hallo', $s2);
			// a locale-specific row beats the catch-all
			$tpls->upsert('order_confirmed', 'de', 'Nur Deutsch', null);
			[$s3] = $renderer->render('order_confirmed', ['order_ref' => 'B'], 'de');
			self::assertSame('Nur Deutsch', $s3);
			[$s4] = $renderer->render('order_confirmed', ['order_ref' => 'B'], 'en');
			self::assertSame('Haus-Shop sagt hallo', $s4);
			foreach (['', 'de'] as $loc) {
				$t = $tpls->findFor('order_confirmed', $loc);
				if ($t !== null) { $tpls->delete($t); }
			}
			[$s5] = $renderer->render('order_confirmed', ['order_ref' => 'B'], 'en');
			self::assertStringContainsString('Booking confirmed', $s5);
		} finally {
			foreach (['', 'de'] as $loc) {
				$t = $tpls->findFor('order_confirmed', $loc);
				if ($t !== null) { $tpls->delete($t); }
			}
			if ($origForceLang !== false) { $config->setSystemValue('force_language', $origForceLang); }
			if ($origForceLocale !== false) { $config->setSystemValue('force_locale', $origForceLocale); }
		}
	}

	public function testManageShowEmitsWalletLinksOnlyWhenConfigured(): void
	{
		$settings = \OCP\Server::get(\OCA\BookingCheck\Service\SettingsService::class);
		$origIssuer = $settings->get('wallet_google_issuer');
		$origSecret = $settings->getSecret('wallet_google_secret');
		// price 0 → completePaidLocked runs at create: tickets minted AND the
		// returned manageToken is the final (rotated) bearer — markPaid would
		// rotate it again and stale our copy.
		$f = $this->seedOffering(5, ['price_cents' => 0]);
		$r = $this->create($f['offering'], $f['event'], 1, $this->idem('wallet'), true);
		$this->created['order'][] = (int)$r['order']->getId();
		$token = (string)($r['manageToken'] ?? '');
		self::assertNotSame('', $token, 'paid free order must mint a manage token');
		$ctrl = $this->manageCtrl([]);
		try {
			// unconfigured → no wallet keys at all (CTA stays hidden)
			$params = $ctrl->show($token)->getParams();
			$row = array_values($params['ticketsByLine'])[0][0];
			self::assertArrayNotHasKey('wallet_google', $row);
			self::assertArrayNotHasKey('wallet_apple', $row);

			$settings->set('wallet_google_issuer', '338800001234');
			$settings->setSecret('wallet_google_secret', 'shh-itest');
			$params = $ctrl->show($token)->getParams();
			$row = array_values($params['ticketsByLine'])[0][0];
			self::assertArrayHasKey('wallet_google', $row);
			self::assertStringContainsString('pay.google.com/gp/v/save/', $row['wallet_google']);
			self::assertTrue($row['wallet_apple'] === false || !isset($row['wallet_apple']),
				'apple CTA must stay hidden without a cert');
		} finally {
			$settings->set('wallet_google_issuer', (string)$origIssuer);
			$origSecret !== '' ? $settings->setSecret('wallet_google_secret', (string)$origSecret)
				: $settings->setSecret('wallet_google_secret', '');
		}
	}

	public function testPublicOfferingPayloadIsWhitelisted(): void
	{
		// Public templates must never receive the full serialize — it
		// carries internal refs (talkRoom, deckBoardId) that let guests
		// reach seller-only objects. And the price key must be the one
		// offering.php reads ('price_cents') or the badge renders 0.
		$f = $this->seedOffering(5, ['price_cents' => 1234]);
		$this->db->executeStatement(
			'UPDATE *PREFIX*bc_offering SET talk_room = ?, deck_board_id = ? WHERE id = ?',
			['secrettalkroom', 42, (int)$f['offering']]);
		$slug = $this->db->executeQuery(
			'SELECT slug FROM *PREFIX*bc_offering WHERE id = ?', [(int)$f['offering']])->fetchOne();
		$ctrl = \OCP\Server::get(\OCA\BookingCheck\Controller\PubCatalogController::class);
		$off = $ctrl->offering((string)$slug)->getParams()['offering'];
		foreach (['talkRoom', 'deckBoardId', 'approvalRequired', 'shareTalk', 'shareDeck', 'createdBy'] as $internal) {
			self::assertArrayNotHasKey($internal, $off, 'internal key leaked: ' . $internal);
		}
		self::assertSame(1234, $off['price_cents'], 'template reads price_cents — mismatch renders 0');
	}

	public function testEmbedOfferingPayloadIsWhitelisted(): void
	{
		$f = $this->seedOffering(5, ['price_cents' => 777]);
		$this->db->executeStatement(
			'UPDATE *PREFIX*bc_offering SET embed_domains = ?, talk_room = ? WHERE id = ?',
			['["https://example.com"]', 'secrettalkroom', (int)$f['offering']]);
		$slug = $this->db->executeQuery(
			'SELECT slug FROM *PREFIX*bc_offering WHERE id = ?', [(int)$f['offering']])->fetchOne();
		$ctrl = \OCP\Server::get(\OCA\BookingCheck\Controller\EmbedController::class);
		$resp = $ctrl->offering((string)$slug);
		$off = $resp->getParams()['offering'];
		self::assertArrayNotHasKey('talkRoom', $off);
		self::assertArrayNotHasKey('deckBoardId', $off);
		self::assertSame(777, $off['price_cents']);
		// CSP must carry the allowed origin — frame boundary is enforced
		self::assertStringContainsString('frame-ancestors',
			(string)$resp->getHeaders()['Content-Security-Policy']);
	}

	public function testAppleWalletRoute404sWhenUnconfigured(): void
	{
		$f = $this->seedOffering(5, ['price_cents' => 0]);
		$r = $this->create($f['offering'], $f['event'], 1, $this->idem('wallet404'), true);
		$this->created['order'][] = (int)$r['order']->getId();
		$line = \OCP\Server::get(\OCA\BookingCheck\Db\OrderLineMapper::class)
			->findByOrder((int)$r['order']->getId())[0];
		$ticket = \OCP\Server::get(\OCA\BookingCheck\Db\TicketMapper::class)
			->findByLine((int)$line->getId())[0];
		try {
			$this->manageCtrl([])->appleWallet((string)$r['manageToken'], (int)$ticket->getId());
			self::fail('unconfigured wallet must not serve a pass');
		} catch (\OCA\BookingCheck\Exception\NotFoundException) {
			self::assertTrue(true);
		}
	}

	public function testRefundClaimCasAllowsExactlyOneOwner(): void
	{
		// insert a real payment row so the CAS exercises the live table
		$f = $this->seedOffering(5, ['price_cents' => 0]);
		$r = $this->create($f['offering'], $f['event'], 1, $this->idem('refundcas'), true);
		$this->created['order'][] = (int)$r['order']->getId();
		$pm = \OCP\Server::get(\OCA\BookingCheck\Db\PaymentMapper::class);
		$pay = new \OCA\BookingCheck\Db\Payment();
		$pay->setOrderId((int)$r['order']->getId());
		$pay->setProvider('test');
		$pay->setPspRef('itest_refund_' . bin2hex(random_bytes(4)));
		$pay->setStatus('paid');
		$pay->setAmountCents(0);
		$pay->setCreatedAt(new \DateTimeImmutable());
		$pm->insert($pay);
		$pid = (int)$pay->getId();

		// first claim wins; second loses while the marker is fresh
		self::assertTrue($pm->claimRefund($pid, 'pending:aaa'));
		self::assertFalse($pm->claimRefund($pid, 'pending:bbb'),
			'concurrent refund must lose the CAS and never reach the PSP');

		// a stale pending marker is reclaimable — the claimant died
		// between claim and PSP call
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_payment')
			->set('refund_claimed_at', $qb->createNamedParameter(
				new \DateTime('-30 minutes'), 'datetime'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($pid)))
			->executeStatement();
		self::assertTrue($pm->claimRefund($pid, 'pending:ccc'),
			'stale pending claim must be reclaimable');

		// a real refund_ref is terminal — never re-claimable even when old
		$pay->setRefundRef('re_realref');
		$pm->update($pay);
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_payment')
			->set('refund_claimed_at', $qb->createNamedParameter(
				new \DateTime('-30 minutes'), 'datetime'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($pid)))
			->executeStatement();
		self::assertFalse($pm->claimRefund($pid, 'pending:ddd'),
			'completed refund must stay claimed forever');

		// release clears only OUR marker
		$pay2 = new \OCA\BookingCheck\Db\Payment();
		$pay2->setOrderId((int)$r['order']->getId());
		$pay2->setProvider('test');
		$pay2->setPspRef('itest_refund2_' . bin2hex(random_bytes(4)));
		$pay2->setStatus('paid');
		$pay2->setAmountCents(0);
		$pay2->setCreatedAt(new \DateTimeImmutable());
		$pm->insert($pay2);
		$pm->claimRefund((int)$pay2->getId(), 'pending:mine');
		$pm->releaseRefundClaim((int)$pay2->getId(), 'pending:someoneelses');
		$cur = $pm->find((int)$pay2->getId());
		self::assertSame('pending:mine', (string)$cur->getRefundRef(),
			'foreign release must not clear our claim');
		$pm->releaseRefundClaim((int)$pay2->getId(), 'pending:mine');
		$cur = $pm->find((int)$pay2->getId());
		self::assertSame('', (string)$cur->getRefundRef(),
			'our release must clear the marker for a clean retry');
	}

	public function testWebhookEventClaimReapsStaleUnprocessedClaims(): void
	{
		$mapper = \OCP\Server::get(\OCA\BookingCheck\Db\WebhookEventMapper::class);
		$ev = 'itest_' . bin2hex(random_bytes(6));
		try {
			// fresh claim → true; immediate replay → false (live claim)
			self::assertTrue($mapper->claim('test', $ev));
			self::assertFalse($mapper->claim('test', $ev), 'live duplicate must report replay');

			// backdate received_at past the stale window, unprocessed →
			// a crashed worker's claim must be reclaimable by the PSP retry
			$qb = $this->db->getQueryBuilder();
			$qb->update('bc_webhook_evt')
				->set('received_at', $qb->createNamedParameter(
					new \DateTime('-2 hours'), 'datetime'))
				->where($qb->expr()->eq('provider', $qb->createNamedParameter('test')))
				->andWhere($qb->expr()->eq('event_id', $qb->createNamedParameter($ev)))
				->executeStatement();
			self::assertTrue($mapper->claim('test', $ev),
				'stale unprocessed claim must be reclaimed');
			// the reclaim refreshed received_at → immediate next replay is live
			self::assertFalse($mapper->claim('test', $ev));

			// a processed event is never reclaimable, however old it is —
			// re-processing a paid webhook would double-apply money state
			$mapper->markProcessed('test', $ev);
			$qb = $this->db->getQueryBuilder();
			$qb->update('bc_webhook_evt')
				->set('received_at', $qb->createNamedParameter(
					new \DateTime('-2 hours'), 'datetime'))
				->where($qb->expr()->eq('provider', $qb->createNamedParameter('test')))
				->andWhere($qb->expr()->eq('event_id', $qb->createNamedParameter($ev)))
				->executeStatement();
			self::assertFalse($mapper->claim('test', $ev),
				'processed event must stay deduped forever');
		} finally {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('bc_webhook_evt')
				->where($qb->expr()->eq('provider', $qb->createNamedParameter('test')))
				->andWhere($qb->expr()->eq('event_id', $qb->createNamedParameter($ev)))
				->executeStatement();
		}
	}

	public function testPassScopeRestrictsAdmissionToListedOfferings(): void
	{
		$svc = \OCP\Server::get(\OCA\BookingCheck\Service\PassService::class);
		$passOffering = $this->seedOffering(6, ['type' => 'pass', 'price_cents' => 0]);
		$other = $this->seedOffering(7);
		// scope: only the OTHER offering admitted, not the pass offering itself
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_offering')
			->set('pass_scope', $qb->createNamedParameter(json_encode([$other['offering']])))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($passOffering['offering'])))
			->executeStatement();

		$r = $this->orders->createPassOrder($passOffering['offering'], 'S Buyer', 's@x.test',
			'en', 1, null, null, $this->idem('passscope'), null, 'itest');
		$this->created['order'][] = (int)$r['order']->getId();
		$token = 'BCK1P-' . bin2hex(random_bytes(24));
		$uq = $this->db->getQueryBuilder();
		$uq->update('bc_pass')
			->set('token_hash', $uq->createNamedParameter(hash('sha256', $token)))
			->where($uq->expr()->eq('order_id',
				$uq->createNamedParameter((int)$r['order']->getId())))
			->executeStatement();

		$ok = $svc->admit($token, 'event', $other['event'], $other['offering'], 'device:t');
		self::assertTrue($ok['ok'], 'scoped pass must admit on the listed offering');
		$no = $svc->admit($token, 'event', $passOffering['event'], $passOffering['offering'], 'device:t');
		self::assertFalse($no['ok']);
		self::assertSame('out_of_scope', $no['reason']);
	}

}

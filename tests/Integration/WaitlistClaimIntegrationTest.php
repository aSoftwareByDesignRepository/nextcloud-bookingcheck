<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Integration;

use OCA\BookingCheck\Exception\NotFoundException;
use OCA\BookingCheck\Exception\ValidationException;
use OCA\BookingCheck\Service\OrderService;
use OCA\BookingCheck\Service\ReservationService;
use OCA\BookingCheck\Service\WaitlistService;
use OCP\IDBConnection;
use Test\TestCase;

/**
 * Waitlist offer → claim → order lifecycle, against the real DB.
 * The claim token is read out of the queued offer mail — the same
 * channel a real buyer receives it on.
 */
final class WaitlistClaimIntegrationTest extends TestCase
{
	private IDBConnection $db;
	private OrderService $orders;
	private WaitlistService $waitlist;
	private array $created = ['offering' => [], 'event' => [], 'order' => [],
		'slot' => [], 'resource' => [], 'slot_res' => []];

	protected function setUp(): void
	{
		parent::setUp();
		\OCA\BookingCheck\Tests\ProLicense::enable(); // waitlist is Pro-gated
		$this->db = \OC::$server->get(IDBConnection::class);
		$this->orders = \OC::$server->get(OrderService::class);
		$this->waitlist = \OC::$server->get(WaitlistService::class);
		\OC::$server->get(ReservationService::class); // sanity: container resolves
	}

	protected function tearDown(): void
	{
		\OCA\BookingCheck\Tests\ProLicense::disable();
		foreach (['event', 'slot'] as $tt) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('bc_waitlist')->where($qb->expr()->eq('target_type',
				$qb->createNamedParameter($tt)))->executeStatement();
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete('bc_email')->where($qb->expr()->like('to_email',
			$qb->createNamedParameter('%@wl.test')))->executeStatement();
		foreach (['order' => 'bc_order', 'event' => 'bc_event', 'offering' => 'bc_offering',
			'slot' => 'bc_slot', 'resource' => 'bc_resource', 'slot_res' => 'bc_slot_res'] as $k => $t) {
			foreach ($this->created[$k] as $id) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete($t)->where($qb->expr()->eq('id',
					$qb->createNamedParameter($id)))->executeStatement();
			}
		}
		if ($this->created['order'] !== []) {
			$ids = $this->created['order'];
			foreach (['bc_order_line' => 'order_id', 'bc_payment' => 'order_id',
				'bc_invoice' => 'order_id'] as $t => $col) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete($t)->where($qb->expr()->in($col,
					$qb->createNamedParameter($ids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
			}
		}
		parent::tearDown();
	}

	private function seed(int $capacity, ?string $tos = null, string $wlMode = 'allocate'): array
	{
		$now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
		$slug = 'wltest-' . bin2hex(random_bytes(4));
		$qb = $this->db->getQueryBuilder();
		$values = [
			'type' => "'event'", 'slug' => "'$slug'", 'title' => "'Waitlist Itest'",
			'status' => "'published'", 'price_cents' => '2500', 'currency' => "'EUR'",
			'vat_bp' => '1900', 'book_series' => '0', 'approval_required' => '0',
			'waitlist_mode' => "'$wlMode'", 'review_display' => '0',
			'sharing_enabled' => '1', 'sharing_scope' => "'occurrence'",
			'upload_max_mb' => '10', 'upload_quota_mb' => '100',
			'list_in_directory' => '0', 'eol_state' => "'none'",
			'created_by' => "'itest'", 'created_at' => "'$now'", 'updated_at' => "'$now'",
		];
		if ($tos !== null) {
			$values['tos_text'] = $qb->createNamedParameter($tos);
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

	private function counters(int $eventId): array
	{
		$qb = $this->db->getQueryBuilder();
		$row = $qb->select('sold', 'held')->from('bc_event')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($eventId)))
			->executeQuery()->fetch();
		return ['sold' => (int)$row['sold'], 'held' => (int)$row['held']];
	}

	private function seedPooled(int $poolCap): array
	{
		$f = $this->seed($poolCap);
		$this->db->getQueryBuilder()->insert('bc_resource')->values([
			'offering_id' => (string)$f['offering'], 'name' => "'Pool Room'",
			'capacity' => (string)$poolCap, 'active' => '1',
		])->executeStatement();
		$resId = (int)$this->db->lastInsertId('oc_bc_resource');
		$this->created['resource'][] = $resId;
		$starts = (new \DateTimeImmutable('+30 days'))->format('Y-m-d H:i:s');
		$ends = (new \DateTimeImmutable('+30 days +1 hour'))->format('Y-m-d H:i:s');
		$this->db->getQueryBuilder()->insert('bc_slot')->values([
			'offering_id' => (string)$f['offering'], 'tpl_id' => '0',
			'starts_at' => "'$starts'", 'ends_at' => "'$ends'",
			'capacity' => (string)$poolCap,
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

	private function poolCounters(int $slotId, int $resourceId): array
	{
		$qb = $this->db->getQueryBuilder();
		$row = $qb->select('sold', 'held')->from('bc_slot_res')
			->where($qb->expr()->eq('slot_id', $qb->createNamedParameter($slotId)))
			->andWhere($qb->expr()->eq('resource_id', $qb->createNamedParameter($resourceId)))
			->executeQuery()->fetch();
		return ['sold' => (int)$row['sold'], 'held' => (int)$row['held']];
	}

	private function waitlistState(int $eventId, string $email): ?string
	{
		$qb = $this->db->getQueryBuilder();
		$v = $qb->select('state')->from('bc_waitlist')
			->where($qb->expr()->eq('target_id', $qb->createNamedParameter($eventId)))
			->andWhere($qb->expr()->eq('email', $qb->createNamedParameter($email)))
			->executeQuery()->fetchOne();
		return $v === false ? null : (string)$v;
	}

	private function claimTokenFromMail(string $email): string
	{
		$qb = $this->db->getQueryBuilder();
		$payload = $qb->select('payload_json')->from('bc_email')
			->where($qb->expr()->eq('kind', $qb->createNamedParameter('waitlist_offer')))
			->andWhere($qb->expr()->eq('to_email', $qb->createNamedParameter($email)))
			->orderBy('id', 'DESC')->setMaxResults(1)
			->executeQuery()->fetchOne();
		self::assertNotFalse($payload, 'offer mail must be queued');
		$url = (string)(json_decode((string)$payload, true)['claim_url'] ?? '');
		self::assertStringContainsString('/pub/claim/', $url,
			'offer mail must carry a clickable claim URL, not a raw token');
		return basename(parse_url($url, PHP_URL_PATH) ?: '');
	}

	/** One sold seat freed → offered → claimed → paid; counters never free mid-path. */
	public function testOfferClaimSettle(): void
	{
		$f = $this->seed(1);
		$r = $this->orders->createOrder($f['offering'], 'First Buyer', 'first@wl.test', 'en',
			[['target_type' => 'event', 'target_id' => $f['event'], 'seats' => 1]],
			[], null, null, 'wl-a' . bin2hex(random_bytes(4)), null, true, 'itest');
		$this->created['order'][] = (int)$r['order']->getId();
		$this->orders->markPaid((int)$r['order']->getId(), 'itest');
		self::assertSame(['sold' => 1, 'held' => 0], $this->counters($f['event']));

		self::assertTrue($this->waitlist->join('event', $f['event'], 'waiter@wl.test', 'en'));
		self::assertFalse($this->waitlist->join('event', $f['event'], 'waiter@wl.test', 'en'),
			'duplicate join must dedupe');

		$this->orders->transitionToCancelled((int)$r['order']->getId(), 'itest');
		// freed seat atomically became the offer hold — never publicly free
		self::assertSame(['sold' => 0, 'held' => 1], $this->counters($f['event']));
		self::assertSame('offered', $this->waitlistState($f['event'], 'waiter@wl.test'));

		$token = $this->claimTokenFromMail('waiter@wl.test');
		$claim = $this->waitlist->acceptClaim($token, 'Waiter Buyer', 'en', $this->orders);
		$this->created['order'][] = (int)$claim['order']->getId();
		self::assertSame('pay', $claim['action']);
		self::assertSame('claimed', $this->waitlistState($f['event'], 'waiter@wl.test'));
		self::assertSame(['sold' => 0, 'held' => 1], $this->counters($f['event']),
			'seat stays held across the claim — never publicly free');

		$this->orders->markPaid((int)$claim['order']->getId(), 'itest');
		self::assertSame(['sold' => 1, 'held' => 0], $this->counters($f['event']));
	}

	/** Claim token is one-shot: replay must not mint a second order or throw a 500. */
	public function testClaimReplayIsOneShot(): void
	{
		$f = $this->seed(1);
		$r = $this->orders->createOrder($f['offering'], 'Buyer B', 'b@wl.test', 'en',
			[['target_type' => 'event', 'target_id' => $f['event'], 'seats' => 1]],
			[], null, null, 'wl-b' . bin2hex(random_bytes(4)), null, true, 'itest');
		$this->created['order'][] = (int)$r['order']->getId();
		$this->orders->markPaid((int)$r['order']->getId(), 'itest');
		$this->waitlist->join('event', $f['event'], 'w2@wl.test', 'en');
		$this->orders->transitionToCancelled((int)$r['order']->getId(), 'itest');

		$token = $this->claimTokenFromMail('w2@wl.test');
		$claim = $this->waitlist->acceptClaim($token, 'W2', 'en', $this->orders);
		$this->created['order'][] = (int)$claim['order']->getId();

		$this->expectException(NotFoundException::class);
		$this->waitlist->acceptClaim($token, 'W2 Again', 'en', $this->orders);
	}

	/** Expired offers reject claims; the row is restored untouched by rollback. */
	public function testExpiredOfferRejectsClaim(): void
	{
		$f = $this->seed(1);
		$r = $this->orders->createOrder($f['offering'], 'Buyer B', 'b2@wl.test', 'en',
			[['target_type' => 'event', 'target_id' => $f['event'], 'seats' => 1]],
			[], null, null, 'wl-c' . bin2hex(random_bytes(4)), null, true, 'itest');
		$this->created['order'][] = (int)$r['order']->getId();
		$this->orders->markPaid((int)$r['order']->getId(), 'itest');
		$this->waitlist->join('event', $f['event'], 'w3@wl.test', 'en');
		$this->orders->transitionToCancelled((int)$r['order']->getId(), 'itest');
		$token = $this->claimTokenFromMail('w3@wl.test');

		$past = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_waitlist')->set('offer_expires_at', $qb->createNamedParameter($past))
			->where($qb->expr()->eq('email', $qb->createNamedParameter('w3@wl.test')))
			->executeStatement();

		try {
			$this->waitlist->acceptClaim($token, 'Late', 'en', $this->orders);
			self::fail('expired offer must reject');
		} catch (NotFoundException) {
			// rollback keeps the row 'offered' for expireOffers() to cascade
			self::assertSame('offered', $this->waitlistState($f['event'], 'w3@wl.test'));
		}
	}

	/** expireOffers releases the stale hold and offers the seat onward atomically. */
	public function testExpireOffersCascadesToNextWaiting(): void
	{
		$f = $this->seed(1);
		$r = $this->orders->createOrder($f['offering'], 'Buyer B', 'b3@wl.test', 'en',
			[['target_type' => 'event', 'target_id' => $f['event'], 'seats' => 1]],
			[], null, null, 'wl-d' . bin2hex(random_bytes(4)), null, true, 'itest');
		$this->created['order'][] = (int)$r['order']->getId();
		$this->orders->markPaid((int)$r['order']->getId(), 'itest');
		$this->waitlist->join('event', $f['event'], 'first@wl.test', 'en');
		$this->waitlist->join('event', $f['event'], 'second@wl.test', 'en');
		$this->orders->transitionToCancelled((int)$r['order']->getId(), 'itest');
		self::assertSame('offered', $this->waitlistState($f['event'], 'first@wl.test'));

		$past = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_waitlist')->set('offer_expires_at', $qb->createNamedParameter($past))
			->where($qb->expr()->eq('email', $qb->createNamedParameter('first@wl.test')))
			->executeStatement();

		self::assertSame(1, $this->waitlist->expireOffers());
		self::assertSame('expired', $this->waitlistState($f['event'], 'first@wl.test'));
		self::assertSame('offered', $this->waitlistState($f['event'], 'second@wl.test'));
		self::assertSame(['sold' => 0, 'held' => 1], $this->counters($f['event']),
			'the seat moves to the next offer hold — never publicly free');
	}

	/**
	 * The claim path shares checkout's legal rules: an offering with terms
	 * cannot be claimed without consent; the wording lands on the order.
	 * Critically, a rejected claim must roll the row back to 'offered' —
	 * the seat is still held for this waiter, not lost.
	 */
	public function testClaimRequiresConsentWhenTosSet(): void
	{
		$f = $this->seed(1, 'Claim terms: no refunds after door time.');
		$r = $this->orders->createOrder($f['offering'], 'Buyer C', 'c@wl.test', 'en',
			[['target_type' => 'event', 'target_id' => $f['event'], 'seats' => 1]],
			[], null, null, 'wl-e' . bin2hex(random_bytes(4)), null, true, 'itest', true);
		$this->created['order'][] = (int)$r['order']->getId();
		$this->orders->markPaid((int)$r['order']->getId(), 'itest');
		$this->waitlist->join('event', $f['event'], 'w4@wl.test', 'en');
		$this->orders->transitionToCancelled((int)$r['order']->getId(), 'itest');
		$token = $this->claimTokenFromMail('w4@wl.test');

		// no consent → rejected, offer row + hold intact for retry
		try {
			$this->waitlist->acceptClaim($token, 'W4 Buyer', 'en', $this->orders, false);
			self::fail('consent is required when terms are configured');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('consent', $e->getFields());
			self::assertSame('offered', $this->waitlistState($f['event'], 'w4@wl.test'),
				'rejected claim must not consume the one-shot token');
			self::assertSame(1, $this->counters($f['event'])['held']);
		}

		// with consent → claimed, snapshot on the order
		$claim = $this->waitlist->acceptClaim($token, 'W4 Buyer', 'en', $this->orders, true);
		$this->created['order'][] = (int)$claim['order']->getId();
		self::assertSame('claimed', $this->waitlistState($f['event'], 'w4@wl.test'));
		$qb = $this->db->getQueryBuilder();
		$text = $qb->select('consent_text')->from('bc_order')
			->where($qb->expr()->eq('id',
				$qb->createNamedParameter((int)$claim['order']->getId())))
			->executeQuery()->fetchOne();
		self::assertSame('Claim terms: no refunds after door time.', $text);
	}

	/** US-104 AC-4 / US-105 AC-2: an EXPIRED pending order releases held
	 *  seats — the waitlist must be offered too, not just on paid cancels. */
	public function testHoldExpiryOffersSeatToWaitlist(): void
	{
		$f = $this->seed(1);
		$r = $this->orders->createOrder($f['offering'], 'Pending Buyer', 'pend@wl.test', 'en',
			[['target_type' => 'event', 'target_id' => $f['event'], 'seats' => 1]],
			[], null, null, 'wl-h' . bin2hex(random_bytes(4)), null, true, 'itest');
		$this->created['order'][] = (int)$r['order']->getId();
		self::assertSame(['sold' => 0, 'held' => 1], $this->counters($f['event']));

		self::assertTrue($this->waitlist->join('event', $f['event'], 'wlh@wl.test', 'en'));
		$this->orders->expire($r['order'], 'itest');
		self::assertSame('offered', $this->waitlistState($f['event'], 'wlh@wl.test'),
			'expired hold must cascade into a waitlist offer');
		self::assertSame(['sold' => 0, 'held' => 1], $this->counters($f['event']),
			'freed seat transfers to the offer hold — never publicly free');
	}

	/** Free tier: notify-mode waitlist joins and gets a booking-link mail —
	 *  the seat stays public (first-to-book), no claim hold. */
	public function testNotifyModeFreeTier(): void
	{
		$f = $this->seed(1, null, 'notify');
		\OCA\BookingCheck\Tests\ProLicense::disable();
		$r = $this->orders->createOrder($f['offering'], 'Paid Buyer', 'paid@wl.test', 'en',
			[['target_type' => 'event', 'target_id' => $f['event'], 'seats' => 1]],
			[], null, null, 'wl-n' . bin2hex(random_bytes(4)), null, true, 'itest');
		$this->created['order'][] = (int)$r['order']->getId();
		$this->orders->markPaid((int)$r['order']->getId(), 'itest');
		self::assertSame(['sold' => 1, 'held' => 0], $this->counters($f['event']));

		self::assertTrue($this->waitlist->join('event', $f['event'], 'nfree@wl.test', 'en'),
			'notify-mode join must work on the free tier');
		$this->orders->transitionToCancelled((int)$r['order']->getId(), 'itest');
		self::assertSame('notified', $this->waitlistState($f['event'], 'nfree@wl.test'));
		self::assertSame(['sold' => 0, 'held' => 0], $this->counters($f['event']),
			'notify leaves the seat public — no hard reservation');
		$qb = $this->db->getQueryBuilder();
		$payload = $qb->select('payload_json')->from('bc_email')
			->where($qb->expr()->eq('kind', $qb->createNamedParameter('waitlist_notify')))
			->andWhere($qb->expr()->eq('to_email', $qb->createNamedParameter('nfree@wl.test')))
			->orderBy('id', 'DESC')->setMaxResults(1)
			->executeQuery()->fetchOne();
		self::assertNotFalse($payload, 'notify mail must be queued');
		$p = json_decode((string)$payload, true);
		self::assertStringContainsString('/pub/o/', (string)($p['offering_url'] ?? ''),
			'notify mail links the public offering page, not a claim token');
	}

	/** Lapsed license: allocate offerings degrade to notify — the queued
	 *  guest is still told (grace), never silently dropped. */
	public function testAllocateDegradesToNotifyOnFreeTier(): void
	{
		$f = $this->seed(1, null, 'allocate');
		\OCA\BookingCheck\Tests\ProLicense::disable();
		$r = $this->orders->createOrder($f['offering'], 'Paid Buyer', 'paid2@wl.test', 'en',
			[['target_type' => 'event', 'target_id' => $f['event'], 'seats' => 1]],
			[], null, null, 'wl-d' . bin2hex(random_bytes(4)), null, true, 'itest');
		$this->created['order'][] = (int)$r['order']->getId();
		$this->orders->markPaid((int)$r['order']->getId(), 'itest');
		$this->waitlist->join('event', $f['event'], 'lapsed@wl.test', 'en');
		$this->orders->transitionToCancelled((int)$r['order']->getId(), 'itest');
		self::assertSame('notified', $this->waitlistState($f['event'], 'lapsed@wl.test'),
			'allocate on an unlicensed tier degrades to notify');
		self::assertSame(0, $this->counters($f['event'])['held']);
	}

	/** Pooled timeslot: the offer-hold must live in bc_slot_res and the
	 *  claim must book the SAME pool — never the slot-level counter. */
	public function testPooledSlotOfferClaimKeepsPoolCounters(): void
	{
		$f = $this->seedPooled(1);
		$r = $this->orders->createOrder($f['offering'], 'Pool Buyer', 'pool@wl.test', 'en',
			[['target_type' => 'slot', 'target_id' => $f['slot'], 'seats' => 1,
				'resource_id' => $f['resource']]],
			[], null, null, 'wl-p' . bin2hex(random_bytes(4)), null, true, 'itest');
		$this->created['order'][] = (int)$r['order']->getId();
		$this->orders->markPaid((int)$r['order']->getId(), 'itest');
		self::assertSame(['sold' => 1, 'held' => 0], $this->poolCounters($f['slot'], $f['resource']));

		self::assertTrue($this->waitlist->join('slot', $f['slot'], 'poolwl@wl.test', 'en'));
		$this->orders->transitionToCancelled((int)$r['order']->getId(), 'itest');
		self::assertSame('offered', $this->waitlistState($f['slot'], 'poolwl@wl.test'));
		self::assertSame(['sold' => 0, 'held' => 1], $this->poolCounters($f['slot'], $f['resource']),
			'the offer-hold sits in the POOL counter, not the slot counter');

		$token = $this->claimTokenFromMail('poolwl@wl.test');
		$r2 = $this->waitlist->acceptClaim($token, 'Pool Claimer', 'en', $this->orders, true);
		$this->created['order'][] = (int)$r2['order']->getId();
		self::assertSame(['sold' => 0, 'held' => 1], $this->poolCounters($f['slot'], $f['resource']),
			'claim re-holds the pooled seat as a pending order');
		$this->orders->markPaid((int)$r2['order']->getId(), 'itest');
		self::assertSame(['sold' => 1, 'held' => 0], $this->poolCounters($f['slot'], $f['resource']),
			'claim consumes the pooled seat — pool sold back to 1');
		$line = $this->db->getQueryBuilder()->select('resource_id')->from('bc_order_line')
			->where('order_id = ' . (int)$r2['order']->getId())
			->executeQuery()->fetchOne();
		self::assertSame($f['resource'], (int)$line,
			'claim order line books the offered pool');
	}

	/** waitlist_mode 'none': joins refuse — no ghost rows waiting forever. */
	public function testNoneModeJoinRefused(): void
	{
		$f = $this->seed(1, null, 'none');
		self::assertFalse($this->waitlist->join('event', $f['event'], 'none@wl.test', 'en'));
	}

	/** Offering unpublished AFTER the join: a seat release must not mail
	 *  guests a dead link. */
	public function testUnpublishedOfferingDoesNotNotify(): void
	{
		$f = $this->seed(1, null, 'notify');
		\OCA\BookingCheck\Tests\ProLicense::disable();
		$r = $this->orders->createOrder($f['offering'], 'Paid Buyer', 'paid4@wl.test', 'en',
			[['target_type' => 'event', 'target_id' => $f['event'], 'seats' => 1]],
			[], null, null, 'wl-u' . bin2hex(random_bytes(4)), null, true, 'itest');
		$this->created['order'][] = (int)$r['order']->getId();
		$this->orders->markPaid((int)$r['order']->getId(), 'itest');
		self::assertTrue($this->waitlist->join('event', $f['event'], 'deadlink@wl.test', 'en'));
		$upd = $this->db->getQueryBuilder();
		$upd->update('bc_offering')
			->set('status', $upd->createNamedParameter('draft'))
			->where($upd->expr()->eq('id', $upd->createNamedParameter((int)$f['offering'])))
			->executeStatement();
		$this->orders->transitionToCancelled((int)$r['order']->getId(), 'itest');
		self::assertSame('waiting', $this->waitlistState($f['event'], 'deadlink@wl.test'),
			'unpublished offering must not mail a dead link');
	}

	/** A notified row must not be re-notified — the next freed seat goes
	 *  to the next waiting guest. */
	public function testNotifyAdvancesToNextWaiting(): void
	{
		$f = $this->seed(1, null, 'notify');
		\OCA\BookingCheck\Tests\ProLicense::disable();
		$r = $this->orders->createOrder($f['offering'], 'Paid Buyer', 'paid3@wl.test', 'en',
			[['target_type' => 'event', 'target_id' => $f['event'], 'seats' => 1]],
			[], null, null, 'wl-x' . bin2hex(random_bytes(4)), null, true, 'itest');
		$this->created['order'][] = (int)$r['order']->getId();
		$this->orders->markPaid((int)$r['order']->getId(), 'itest');
		$this->waitlist->join('event', $f['event'], 'first@wl.test', 'en');
		$this->waitlist->join('event', $f['event'], 'second@wl.test', 'en');
		$this->orders->transitionToCancelled((int)$r['order']->getId(), 'itest');
		self::assertSame('notified', $this->waitlistState($f['event'], 'first@wl.test'));
		self::assertSame('waiting', $this->waitlistState($f['event'], 'second@wl.test'),
			'one freed seat notifies one guest');
	}
}

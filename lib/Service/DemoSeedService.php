<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\Db\CompanionToken;
use OCA\BookingCheck\Db\CompanionTokenMapper;
use OCA\BookingCheck\Db\Event;
use OCA\BookingCheck\Db\EventMapper;
use OCA\BookingCheck\Db\Offering;
use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Db\Series;
use OCA\BookingCheck\Db\SeriesMapper;
use OCA\BookingCheck\Db\TicketMapper;
use OCP\IDBConnection;

use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Idempotent demo dataset for BookingCheck Manager / Scan reviewers,
 * Play screenshots and sales demos — no web admin UI required.
 *
 * Creates: published offering "Demo Night — Riverside", three single events,
 * one weekly series with member events, three paid orders (one attendee
 * already checked in), and door + manager companion device tokens.
 * Re-run safe: the offering slug and deterministic order idem tokens make
 * every step a no-op once it exists. Never run on production instances.
 */
final class DemoSeedService
{
	// unique-conflict:ok — ensureOffering carries the REASON_UNIQUE catch → adopt winner's id; the remaining inserts are events/series (no unique index) and crypto-random companion token hashes.
	public const SLUG = 'demo-night-riverside';
	private const IDEM_PREFIX = 'seed-demo-';

	public function __construct(
		private readonly IUserManager $userManager,
		private readonly IUserSession $userSession,
		private readonly IDBConnection $db,
		private readonly OfferingMapper $offerings,
		private readonly EventMapper $events,
		private readonly SeriesMapper $series,
		private readonly OrderService $orders,
		private readonly TicketService $tickets,
		private readonly TicketMapper $ticketMapper,
		private readonly TokenService $tokens,
		private readonly CompanionTokenMapper $companions,
	) {
	}

	/**
	 * @return array{offeringId:int, eventIds:int[], seriesId:int,
	 *   orderRefs:string[], qrPayloads:string[], doorToken:string,
	 *   managerToken:string, checkedIn:string}
	 */
	public function run(string $adminUid, bool $forceTokens = false): array
	{
		$admin = $this->userManager->get($adminUid);
		if ($admin === null) {
			throw new \RuntimeException('User "' . $adminUid . '" does not exist.');
		}
		$this->userSession->setUser($admin);

		$offeringId = $this->ensureOffering($adminUid);
		[$eventIds, $seriesId] = $this->ensureSchedule($offeringId);
		$orderRefs = $this->ensureOrders($offeringId, $eventIds, $seriesId);
		$qrPayloads = $this->collectQrPayloads();
		$checkedIn = $this->ensureOneCheckedIn();
		$doorToken = $this->ensureDeviceToken('Play review door', 'door', $adminUid, $forceTokens);
		$managerToken = $this->ensureDeviceToken('Play review manager', 'manager', $adminUid, $forceTokens);

		return [
			'offeringId' => $offeringId,
			'eventIds' => $eventIds,
			'seriesId' => $seriesId,
			'orderRefs' => $orderRefs,
			'qrPayloads' => $qrPayloads,
			'doorToken' => $doorToken,
			'managerToken' => $managerToken,
			'checkedIn' => $checkedIn,
		];
	}

	private function ensureOffering(string $adminUid): int
	{
		$existing = $this->offerings->findBySlug(self::SLUG);
		if ($existing !== null) {
			return (int)$existing->getId();
		}
		$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$o = new Offering();
		$o->setType('event');
		$o->setSlug(self::SLUG);
		$o->setTitle('Demo Night — Riverside Hall');
		$o->setDescr('A seeded demo event for BookingCheck Manager & Scan. Doors 18:30, show 19:30.');
		$o->setStatus('published');
		$o->setPriceCents(1500);
		$o->setCurrency('EUR');
		$o->setVatBp(1900);
		$o->setCancelPolicy(null);
		$o->setBookSeries(0);
		$o->setDeckBoardId(null);
		$o->setTalkRoom(null);
		$o->setApprovalRequired(0);
		$o->setApprovalHoldCap(null);
		$o->setWaitlistMode('off');
		$o->setReviewDisplay(0);
		$o->setLogoFileRef(null);
		$o->setAccentHex('#0082c9');
		$o->setSharingEnabled(0);
		$o->setSharingScope('private');
		$o->setUploadMaxMb(5);
		$o->setUploadQuotaMb(50);
		$o->setEmbedDomains(null);
		$o->setSalesFrom($now->modify('-1 day'));
		$o->setSalesTo(null);
		$o->setTosText(null);
		$o->setListInDirectory(1);
		$o->setEolState('none');
		$o->setEolPolicy(null);
		$o->setEolActionAt(null);
		$o->setCreatedBy($adminUid);
		$o->setCreatedAt($now);
		$o->setUpdatedAt($now);
		$o->setReminderDays(0);
		$o->setDynBp(0);
		$o->setDynStepPct(0);
		$o->setDepositBp(0);
		$o->setInvoicePay(0);
		$o->setPassScope(null);
		// findBySlug→insert is check-then-act: a concurrent seed run wins —
		// the unique index decides, we adopt the winner's id and stay
		// idempotent instead of crashing the second run.
		try {
			return (int)$this->offerings->insert($o)->getId();
		} catch (\OCP\DB\Exception $e) {
			if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			$winner = $this->offerings->findBySlug(self::SLUG);
			if ($winner === null) {
				throw $e;
			}
			return (int)$winner->getId();
		}
	}

	/**
	 * @return array{0:int[], 1:int}
	 */
	private function ensureSchedule(int $offeringId): array
	{
		$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$eventIds = [];
		$planned = [];
		$starts = [
			$now->setTime(19, 30),
			$now->modify('+1 day')->setTime(19, 30),
			$now->modify('+7 days')->setTime(19, 30),
		];
		foreach ($starts as $start) {
			while ($start <= $now || in_array($start->format('Y-m-d H:i'), $planned, true)) {
				$start = $start->modify('+1 day');
			}
			$planned[] = $start->format('Y-m-d H:i');
			$id = $this->findEventAt($offeringId, null, $start);
			if ($id === null) {
				$e = new Event();
				$e->setOfferingId($offeringId);
				$e->setStartsAt($start);
				$e->setEndsAt($start->modify('+3 hours'));
				$e->setCapacity(60);
				$e->setSold(0);
				$e->setPriceCents(null);
				$e->setHeld(0);
				$e->setStatus('scheduled');
				$e->setSeriesId(null);
				$id = (int)$this->events->insert($e)->getId();
			}
			$eventIds[] = $id;
		}

		$seriesId = $this->findSeries($offeringId, 'Weekly Demo Session');
		if ($seriesId === null) {
			$s = new Series();
			$s->setOfferingId($offeringId);
			$s->setCapacity(40);
			$s->setTitle('Weekly Demo Session');
			$seriesId = (int)$this->series->insert($s)->getId();
			for ($w = 0; $w < 4; $w++) {
				$start = $now->modify('+' . ($w + 1) . ' weeks')->setTime(18, 0);
				$e = new Event();
				$e->setOfferingId($offeringId);
				$e->setStartsAt($start);
				$e->setEndsAt($start->modify('+2 hours'));
				$e->setCapacity(40);
				$e->setSold(0);
				$e->setPriceCents(null);
				$e->setHeld(0);
				$e->setStatus('scheduled');
				$e->setSeriesId($seriesId);
				$this->events->insert($e);
			}
		}
		return [$eventIds, $seriesId];
	}

	private function findEventAt(int $offeringId, ?int $seriesId, \DateTimeImmutable $start): ?int
	{
		foreach ($this->events->findByOffering($offeringId) as $e) {
			if ($e->getStartsAt()->format('Y-m-d H:i') === $start->format('Y-m-d H:i')
				&& ($seriesId === null ? $e->getSeriesId() === null : (int)$e->getSeriesId() === $seriesId)) {
				return (int)$e->getId();
			}
		}
		return null;
	}

	private function findSeries(int $offeringId, string $title): ?int
	{
		foreach ($this->series->findAll() as $s) {
			if ((int)$s->getOfferingId() === $offeringId && $s->getTitle() === $title) {
				return (int)$s->getId();
			}
		}
		return null;
	}

	/**
	 * @param int[] $eventIds
	 * @return string[] order refs
	 */
	private function ensureOrders(int $offeringId, array $eventIds, int $seriesId): array
	{
		$buyers = [
			['Marta Jensen', 'marta.jensen@example.org', 'event', $eventIds[0], 2],
			['Tom Weißenborn', 'tom.weissenborn@example.org', 'series', $seriesId, 3],
			['Aisha Demir', 'aisha.demir@example.org', 'event', $eventIds[1], 1],
		];
		$refs = [];
		foreach ($buyers as $i => [$name, $email, $type, $targetId, $seats]) {
			$idem = self::IDEM_PREFIX . ($i + 1);
			$res = $this->orders->createOrder(
				$offeringId,
				$name,
				$email,
				'en',
				[['target_type' => $type, 'target_id' => $targetId, 'seats' => $seats]],
				[],
				null,
				null,
				$idem,
				null,
				false,
				'seed',
			);
			$order = $res['order'];
			if ($order->getStatus() !== 'paid') {
				$order = $this->orders->markPaid((int)$order->getId(), 'seed');
			}
			$refs[] = $order->getRef();
		}
		return $refs;
	}

	/**
	 * @return string[]
	 */
	private function collectQrPayloads(): array
	{
		$payloads = [];
		$qb = $this->db->getQueryBuilder();
		$rows = $qb->select('t.id')->from('bc_ticket', 't')
			->innerJoin('t', 'bc_order_line', 'ol', $qb->expr()->eq('ol.id', 't.line_id'))
			->innerJoin('ol', 'bc_order', 'o', $qb->expr()->eq('o.id', 'ol.order_id'))
			->where($qb->expr()->like('o.idem_token', $qb->createNamedParameter(self::IDEM_PREFIX . '%')))
			->andWhere($qb->expr()->isNull('t.revoked_at'))
			->setMaxResults(4)
			->executeQuery()->fetchAll();
		foreach ($rows as $r) {
			$t = $this->ticketMapper->find((int)$r['id']);
			if ($t === null) {
				continue;
			}
			$p = $this->tickets->qrPayload($t);
			if ($p !== null) {
				$payloads[] = $p;
			}
		}
		return $payloads;
	}

	private function ensureOneCheckedIn(): string
	{
		$qb = $this->db->getQueryBuilder();
		$done = $qb->select('t.attendee_name')->from('bc_ticket', 't')
			->innerJoin('t', 'bc_order_line', 'ol', $qb->expr()->eq('ol.id', 't.line_id'))
			->innerJoin('ol', 'bc_order', 'o', $qb->expr()->eq('o.id', 'ol.order_id'))
			->where($qb->expr()->like('o.idem_token', $qb->createNamedParameter(self::IDEM_PREFIX . '%')))
			->andWhere($qb->expr()->isNotNull('t.checked_in_at'))
			->setMaxResults(1)
			->executeQuery()->fetchOne();
		if (is_string($done) && $done !== '') {
			return $done;
		}
		$qb = $this->db->getQueryBuilder();
		$id = $qb->select('t.id')->from('bc_ticket', 't')
			->innerJoin('t', 'bc_order_line', 'ol', $qb->expr()->eq('ol.id', 't.line_id'))
			->innerJoin('ol', 'bc_order', 'o', $qb->expr()->eq('o.id', 'ol.order_id'))
			->where($qb->expr()->like('o.idem_token', $qb->createNamedParameter(self::IDEM_PREFIX . '%')))
			->andWhere($qb->expr()->isNull('t.checked_in_at'))
			->andWhere($qb->expr()->isNull('t.revoked_at'))
			->setMaxResults(1)
			->executeQuery()->fetchOne();
		if ($id === false) {
			return '';
		}
		$t = $this->ticketMapper->find((int)$id);
		if ($t === null) {
			return '';
		}
		$payload = $this->tickets->qrPayload($t);
		if ($payload === null) {
			return '';
		}
		$this->tickets->checkInByToken($payload, 'seed');
		return (string)$t->getAttendeeName();
	}

	private function ensureDeviceToken(string $label, string $role, string $adminUid, bool $force): string
	{
		foreach ($this->companions->findAll() as $d) {
			if ($d->getLabel() === $label && $d->getRole() === $role && $d->getRevokedAt() === null && !$force) {
				return '(already issued — plaintext unrecoverable; re-run with --rotate-tokens to mint a fresh one)';
			}
		}
		[$token, $hash] = $this->tokens->mint();
		$d = new CompanionToken();
		$d->setTokenHash($hash);
		$d->setLabel($label);
		$d->setRole($role);
		$d->setOfferingIds('');
		$d->setCreatedBy($adminUid);
		$d->setCreatedAt(new \DateTimeImmutable());
		$d->setLastSeenAt(null);
		$d->setRevokedAt(null);
		$d->setPushToken(null);
		$d->setPushKind(null);
		$d->setLocale(null);
		$this->companions->insert($d);
		return $token;
	}
}

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\Db\WaitlistEntry;
use OCA\BookingCheck\Db\WaitlistEntryMapper;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCA\BookingCheck\Exception\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Allocated waitlist (§9.13).
 *
 * offerSeat() is called INSIDE the releasing transaction: the freed seat
 * becomes held-for-offer in the same commit — it is never publicly free
 * between release and offer. Claims consume the offer hold directly.
 */
class WaitlistService
{
	public function __construct(
		private readonly WaitlistEntryMapper $waitlist,
		private readonly ReservationService $inventory,
		private readonly TokenService $tokens,
		private readonly EmailService $email,
		private readonly SettingsService $settings,
		private readonly IDBConnection $db,
		private readonly \OCA\BookingCheck\Db\OfferingMapper $offerings,
		private readonly IURLGenerator $url,
		private readonly LoggerInterface $logger,
		private readonly LicenseService $license,
	) {
	}

	/**
	 * Offer one freed seat to the first waiting row.
	 * Caller holds the transaction. Returns true when an offer was placed
	 * (caller then converts the freed seat to a hold).
	 */
	public function offerSeat(string $targetType, int $targetId): bool
	{
		// Pro-gated — service-level backstop: order transitions call this
		// from free paths; on the free tier a freed seat goes public
		if (!$this->license->hasFeature('waitlist')) {
			return false;
		}
		$row = $this->waitlist->firstWaitingForUpdate($targetType, $targetId);
		if ($row === null) {
			return false;
		}
		[$token, $hash] = $this->tokens->mint();
		$expires = new \DateTimeImmutable('+' . $this->settings->getInt('waitlist_offer_h', 24) . ' hours');
		$row->setState('offered');
		$row->setOfferExpiresAt($expires);
		$row->setClaimTokenHash($hash);
		$this->waitlist->update($row);
		$this->email->queue('waitlist_offer', $row->getEmail(), $row->getLocale(),
			['claim_url' => $this->url->linkToRouteAbsolute(
				'bookingcheck.claim.show', ['token' => $token]),
			 'target_type' => $targetType, 'target_id' => $targetId,
			 'expires_at' => $expires->format(\DateTimeInterface::ATOM)], capped: true);
		return true;
	}

	/** Join the list; dedupe by (email, target). Fails silently — the
	 *  controller must stay uniform-202 to avoid an existence oracle. */
	public function join(string $targetType, int $targetId, string $email, string $locale): bool
	{
		$email = strtolower(trim($email));
		// Pro-gated — silently declined like any invalid target (uniform 202)
		if (!$this->license->hasFeature('waitlist') || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return false;
		}
		// the target must resolve to a published offering with waitlisting
		// enabled — otherwise arbitrary ids would create never-served rows.
		$offeringId = $this->offeringForTarget($targetType, $targetId);
		if ($offeringId === null) {
			return false;
		}
		$offering = $this->offerings->find($offeringId);
		if ($offering === null || $offering->getStatus() !== 'published'
			|| !in_array((string)$offering->getWaitlistMode(), ['notify', 'allocate'], true)) {
			return false;
		}
		$e = new WaitlistEntry();
		$e->setTargetType($targetType);
		$e->setTargetId($targetId);
		$e->setEmail($email);
		$e->setLocale($locale);
		$e->setState('waiting');
		$e->setCreatedAt(new \DateTimeImmutable());
		try {
			$this->waitlist->insert($e);
			return true;
		} catch (\Throwable) {
			return false; // unique dedup — already listed
		}
	}

	/**
	 * Accept a claim token: the offer's held seat converts into a real order
	 * inside one transaction — release our hold, createOrder re-holds it.
	 * If order creation fails, rollback restores the offer untouched, so the
	 * seat is never double-promised and never publicly free mid-claim.
	 *
	 * @return array{order:\OCA\BookingCheck\Db\Order, manageToken:string, action:string}
	 */
	public function acceptClaim(string $token, string $buyerName, string $locale,
		OrderService $orders, bool $consented = false): array
	{
		if ($token === '' || strlen($token) > 128) {
			throw new NotFoundException();
		}
		$this->db->beginTransaction();
		try {
			$row = $this->waitlist->findByClaimTokenHash($this->tokens->hash($token));
			if ($row === null) {
				throw new NotFoundException();
			}
			$offeringId = $this->offeringForTarget($row->getTargetType(), (int)$row->getTargetId());
			if ($offeringId === null || $this->offerings->findForUpdate($offeringId) === null) {
				throw new NotFoundException();
			}
			// global lock order: offering → inventory → waitlist row.
			// Locking the waitlist row first would edge waitlist→inventory
			// against release paths' inventory→waitlist — a deadlock cycle.
			$this->inventory->prelock([[
				'target_type' => $row->getTargetType(),
				'target_id' => (int)$row->getTargetId(),
			]]);
			$locked = $this->waitlist->findForUpdate((int)$row->getId());
			if ($locked === null || $locked->getState() !== 'offered'
				|| ($locked->getOfferExpiresAt() !== null
					&& $locked->getOfferExpiresAt() < new \DateTimeImmutable())) {
				throw new NotFoundException();
			}

			// claim first — a failed createOrder rolls this back to 'offered'
			$locked->setState('claimed');
			$locked->setClaimTokenHash(null); // one-shot: the token is dead now
			$this->waitlist->update($locked);

			// free our offer-hold, then let the normal order path re-hold it
			$this->inventory->releaseHold($locked->getTargetType(), (int)$locked->getTargetId(), 1);
			$result = $orders->createOrder(
				$offeringId, $buyerName, $locked->getEmail(), $locale,
				[['target_type' => $locked->getTargetType(), 'target_id' => (int)$locked->getTargetId(), 'seats' => 1]],
				[], null, null,
				'claim-' . (int)$locked->getId() . '-' . substr($this->tokens->hash($token), 0, 16),
				null, false, 'waitlist-claim', $consented);
			$this->db->commit();
			return $result;
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
	}

	/**
	 * Admin removal. An 'offered' entry holds a reserved seat — declining
	 * must release it and offer it onward, inside the same transaction and
	 * lock order as expireOffers (offering → inventory → waitlist row).
	 */
	public function decline(int $id): bool
	{
		$this->db->beginTransaction();
		try {
			// lock ordering — read the row first for its target (no lock
			// needed to learn WHERE to point the locks)
			$probe = $this->waitlist->find($id);
			if ($probe === null) {
				$this->db->commit();
				return false;
			}
			if ($probe->getState() === 'offered') {
				$offeringId = $this->offeringForTarget($probe->getTargetType(), (int)$probe->getTargetId());
				if ($offeringId !== null) {
					$this->offerings->findForUpdate($offeringId);
				}
				$this->inventory->prelock([[
					'target_type' => $probe->getTargetType(),
					'target_id' => (int)$probe->getTargetId(),
				]]);
			}
			$row = $this->waitlist->findForUpdate($id);
			if ($row === null) {
				$this->db->commit();
				return false;
			}
			$wasOffered = $row->getState() === 'offered';
			$row->setState('declined');
			$row->setClaimTokenHash(null);
			$row->setOfferExpiresAt(null);
			$this->waitlist->update($row);
			if ($wasOffered) {
				$this->inventory->releaseHold($row->getTargetType(), (int)$row->getTargetId(), 1);
				if ($this->offerSeat($row->getTargetType(), (int)$row->getTargetId())) {
					$this->inventory->hold($row->getTargetType(), (int)$row->getTargetId(), 1);
				}
			}
			$this->db->commit();
			return true;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	public function offeringForTarget(string $targetType, int $targetId): ?int
	{
		$map = ['event' => 'bc_event', 'series' => 'bc_series', 'slot' => 'bc_slot'];
		$table = $map[$targetType] ?? null;
		if ($table === null) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$v = $qb->select('offering_id')->from($table)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($targetId)))
			->executeQuery()->fetchOne();
		return $v === false ? null : (int)$v;
	}

	/**
	 * Expire stale offers. For each: release its held seat, then immediately
	 * try to offer it to the next waiting row — all in one transaction, so
	 * the seat is never publicly free mid-cascade.
	 */
	public function expireOffers(): int
	{
		$expired = $this->waitlist->expiredOffers();
		$n = 0;
		foreach ($expired as $row) {
			$this->db->beginTransaction();
			try {
				// same global lock order as acceptClaim: offering →
				// inventory → waitlist row (deadlock-free by hierarchy).
				$offeringId = $this->offeringForTarget($row->getTargetType(), (int)$row->getTargetId());
				if ($offeringId !== null) {
					$this->offerings->findForUpdate($offeringId);
				}
				$this->inventory->prelock([[
					'target_type' => $row->getTargetType(),
					'target_id' => (int)$row->getTargetId(),
				]]);
				$locked = $this->waitlist->findForUpdate((int)$row->getId());
				if ($locked === null || $locked->getState() !== 'offered') {
					$this->db->commit();
					continue;
				}
				$locked->setState('expired');
				$locked->setClaimTokenHash(null);
				$this->waitlist->update($locked);
				// release the offer-hold, then offer the seat onward.
				$this->inventory->releaseHold($locked->getTargetType(), (int)$locked->getTargetId(), 1);
				if ($this->offerSeat($locked->getTargetType(), (int)$locked->getTargetId())) {
					$this->inventory->hold($locked->getTargetType(), (int)$locked->getTargetId(), 1);
				}
				$this->db->commit();
				$n++;
			} catch (\Throwable $e) {
				$this->db->rollBack();
				// a swallowed error here means an offered seat is stuck forever
				// — surface it loudly, keep processing the other rows
				$this->logger->warning('waitlist offer expiry failed: ' . $e->getMessage(),
					['waitlist_id' => (int)$row->getId()]);
			}
		}
		return $n;
	}
}

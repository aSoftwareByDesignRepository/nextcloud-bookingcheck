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
use OCA\BookingCheck\Exception\NotFoundException;

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
	) {
	}

	/**
	 * Offer one freed seat to the first waiting row.
	 * Caller holds the transaction. Returns true when an offer was placed
	 * (caller then converts the freed seat to a hold).
	 */
	public function offerSeat(string $targetType, int $targetId): bool
	{
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
			['claim_token' => $token, 'target_type' => $targetType, 'target_id' => $targetId,
			 'expires_at' => $expires->format(\DateTimeInterface::ATOM)], capped: true);
		return true;
	}

	/** Join the list; dedupe by (email, target). */
	public function join(string $targetType, int $targetId, string $email, string $locale): bool
	{
		$email = strtolower(trim($email));
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
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
		OrderService $orders): array
	{
		if ($token === '' || strlen($token) > 128) {
			throw new NotFoundException();
		}
		$this->db->beginTransaction();
		try {
			$row = $this->waitlist->findByClaimTokenHash($this->tokens->hash($token));
			$locked = $row !== null ? $this->waitlist->findForUpdate((int)$row->getId()) : null;
			if ($locked === null || $locked->getState() !== 'offered'
				|| ($locked->getOfferExpiresAt() !== null
					&& $locked->getOfferExpiresAt() < new \DateTimeImmutable())) {
				throw new NotFoundException();
			}
			$offeringId = $this->offeringForTarget($locked->getTargetType(), (int)$locked->getTargetId());
			if ($offeringId === null) {
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
				null, false, 'waitlist-claim');
			$this->db->commit();
			return $result;
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
	}

	private function offeringForTarget(string $targetType, int $targetId): ?int
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
			} catch (\Throwable) {
				$this->db->rollBack();
			}
		}
		return $n;
	}
}

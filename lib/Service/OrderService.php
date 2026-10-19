<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\Db\EmailOutboxMapper;
use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Db\Order;
use OCA\BookingCheck\Db\OrderLine;
use OCA\BookingCheck\Db\OrderLineMapper;
use OCA\BookingCheck\Db\OrderMapper;
use OCA\BookingCheck\Db\PromoMapper;
use OCA\BookingCheck\Db\VoucherMapper;
use OCA\BookingCheck\Exception\CapacityException;
use OCA\BookingCheck\Exception\NotFoundException;
use OCA\BookingCheck\Exception\StateConflictException;
use OCA\BookingCheck\Exception\ValidationException;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
use OCP\IURLGenerator;

/**
 * Order lifecycle — §9.3 state machine.
 *
 * Every transition is `UPDATE bc_order … WHERE status IN (<legal from>)`
 * inside a transaction: affected-rows=0 ⇒ the transition was stolen by a
 * racing transition; the loser re-reads and aborts. Idempotent callers
 * (webhook retry, double-click) get the already-transitioned order back
 * when the current state equals the requested target.
 */
class OrderService
{
	public const ST_CREATED = 'created';
	public const ST_PENDING_PAYMENT = 'pending_payment';
	public const ST_PENDING_APPROVAL = 'pending_approval';
	public const ST_APPROVED = 'approved';
	public const ST_PAID = 'paid';
	public const ST_FAILED = 'failed';
	public const ST_EXPIRED = 'expired';
	public const ST_CANCELLED = 'cancelled';
	public const ST_REFUNDED = 'refunded';
	public const ST_DECLINED = 'declined';

	public const TERMINAL = [self::ST_CANCELLED, self::ST_REFUNDED, self::ST_FAILED, self::ST_EXPIRED, self::ST_DECLINED];

	public function __construct(
		private readonly OrderMapper $orders,
		private readonly OrderLineMapper $lines,
		private readonly OfferingMapper $offerings,
		private readonly VoucherMapper $vouchers,
		private readonly PromoMapper $promos,
		private readonly PricingService $pricing,
		private readonly ReservationService $inventory,
		private readonly InvoiceService $invoices,
		private readonly TicketService $tickets,
		private readonly EmailService $email,
		private readonly AuditService $audit,
		private readonly TokenService $tokens,
		private readonly SettingsService $settings,
		private readonly WaitlistService $waitlist,
		private readonly IDBConnection $db,
		private readonly IURLGenerator $url,
		private readonly ?CrmSyncService $crmSync = null,
		private readonly ?SellerNotifyService $sellerNotify = null,
		private readonly ?OutboundWebhookService $webhooks = null,
	) {
	}

	// ---------------------------------------------------------------
	// Creation — the checkout spine
	// ---------------------------------------------------------------

	/**
	 * @return array{order:Order, manageToken:string, action:string}
	 *   action: 'pay' (create PSP session), 'approve' (await seller), 'done' (zero-total)
	 */
	public function createOrder(
		int $offeringId,
		string $buyerName,
		string $buyerEmail,
		string $locale,
		array $seatLines,
		array $addonLines,
		?string $promoCode,
		?string $voucherCode,
		string $idemToken,
		?array $billing,
		bool $isTest,
		string $actor,
		bool $consented = false,
	): array {
		// ---- idempotency first: same token ⇒ same order, no second hold ----
		$existing = $this->orders->findByIdemToken($idemToken);
		if ($existing !== null) {
			return ['order' => $existing, 'manageToken' => '', 'action' => 'idempotent-replay'];
		}
		if (!preg_match('/^[A-Za-z0-9_-]{8,36}$/', $idemToken)) {
			throw new ValidationException(['idem_token' => 'Invalid idempotency token.']);
		}
		$this->validateBuyer($buyerName, $buyerEmail, $locale);

		$this->db->beginTransaction();
		try {
			$offering = $this->offerings->findForUpdate($offeringId);
			if ($offering === null || $offering->getStatus() !== 'published') {
				throw new NotFoundException();
			}
			$this->assertSalesWindowOpen($offering);

			// US-301 AC-3: a configured terms text makes consent mandatory and
			// snapshots the exact wording onto the order — later edits must not
			// retro-rewrite what was agreed.
			$tosText = trim((string)($offering->getTosText() ?? ''));
			if ($tosText !== '' && !$consented) {
				throw new ValidationException(['consent' => 'Please accept the terms to continue.']);
			}

			// targets must belong to THIS offering — otherwise a foreign
			// target_id would book seats cross-tenant at this price.
			$this->inventory->assertTargetsOwnedBy($offeringId, $seatLines);
			// deterministic pre-lock of every inventory row (incl. series
			// member events) — see ReservationService::prelock.
			$this->inventory->prelock($seatLines);

			$offeringRow = [
				'id' => $offeringId,
				'price_cents' => $offering->getPriceCents(),
				'currency' => $offering->getCurrency(),
				'vat_bp' => $offering->getVatBp(),
			];
			$priced = $this->pricing->price($offeringRow, $seatLines, $addonLines,
				$promoCode, $voucherCode, !empty($billing['reverse_charge']));

			// approval path: cap total approval-held seats when configured
			$needsApproval = (int)$offering->getApprovalRequired() === 1 && !$isTest;
			if ($needsApproval) {
				$this->assertApprovalCap($offering, $seatLines);
			}

			[$manageToken, $manageHash] = $this->tokens->mint();
			$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

			$order = new Order();
			// ref collision precheck — unique index is the backstop; a
			// collision here would otherwise surface as a bare 500.
			$ref = null;
			for ($try = 0; $try < 5; $try++) {
				$cand = $this->tokens->publicRef('BCK', (int)$now->format('Y'));
				if ($this->orders->findByRef($cand) === null) {
					$ref = $cand;
					break;
				}
			}
			if ($ref === null) {
				throw new StateConflictException('Could not mint a unique order reference', 'RETRY');
			}
			$order->setRef($ref);
			$order->setOfferingId($offeringId);
			$order->setBuyerName($buyerName);
			$order->setBuyerEmail(strtolower(trim($buyerEmail)));
			$order->setBuyerLocale($locale);
			$order->setAmountCents($priced['total']);
			$order->setCurrency($priced['currency']);
			$order->setIdemToken($idemToken);
			$order->setManageTokenHash($manageHash);
			$order->setIsTest($isTest ? 1 : 0);
			$order->setVoucherId($priced['voucherId']);
			$order->setDiscountCents($priced['voucherCents']);
			$order->setPromoId($priced['promoId']);
			$order->setPromoCode($priced['promoCode']);
			$order->setPromoCents($priced['promoCents']);
			$order->setCreatedAt($now);
			$order->setStatus(self::ST_CREATED);
			if ($billing !== null) {
				$order->setBuyerCompany($billing['company'] ?? null);
				$order->setBillingCountry($billing['country'] ?? null);
				$order->setVatId($billing['vat_id'] ?? null);
				$order->setReverseCharge(!empty($billing['reverse_charge']) ? 1 : 0);
				$order->setViesStatus($billing['vies_status'] ?? 'none');
			}
			if ($tosText !== '') {
				$order->setConsentAt($now);
				$order->setConsentText($tosText);
			}
			$this->orders->insert($order);

			// ---- holds: deterministic target order (deadlock-free by sort) ----
			$holds = $this->sortedSeatHolds($seatLines);
			foreach ($holds as $h) {
				$this->inventory->hold($h['target_type'], $h['target_id'], $h['seats'], $h['resource_id'] ?? null);
			}

			// ---- money locks: voucher + promo redeem atomically -------------
			if ($priced['voucherId'] !== null && $priced['voucherCents'] > 0) {
				if (!$this->vouchers->redeem($priced['voucherId'], $priced['voucherCents'])) {
					throw new ValidationException(['voucher' => 'Voucher could not be applied.']);
				}
			}
			if ($priced['promoId'] !== null) {
				if (!$this->promos->incrementUses($priced['promoId'])) {
					throw new ValidationException(['promo' => 'Promo code could not be applied.']);
				}
			}

			foreach ($priced['lines'] as $l) {
				$line = new OrderLine();
				$line->setOrderId((int)$order->getId());
				$line->setTargetType($l['target_type']);
				$line->setTargetId($l['target_id']);
				$line->setLineKind($l['line_kind']);
				$line->setResourceId($l['resource_id']);
				$line->setPriceCatId($l['price_cat_id']);
				$line->setCatName($l['cat_name']);
				$line->setSeats($l['seats']);
				$line->setUnitCents($l['unit_cents']);
				$line->setLineCents($l['line_cents']);
				$this->lines->insert($line);
			}

			// ---- branch: approval / zero-total / payment --------------------
			if ($needsApproval) {
				$this->setStatusLocked($order, [self::ST_CREATED], self::ST_PENDING_APPROVAL,
					$now->modify('+' . $this->settings->getInt('approval_hold_min', 4320) . ' minutes'));
				$this->email->queue('approval_request', $this->settings->get('seller_email'), 'en',
					['order_ref' => $order->getRef(), 'offering' => $offering->getTitle()]);
				if ($this->sellerNotify !== null) {
					$this->sellerNotify->approvalNeeded($order, (string)$offering->getCreatedBy(),
						(string)$offering->getTitle());
				}
				$this->email->queue('approval_pending', $order->getBuyerEmail(), $order->getBuyerLocale(),
					['order_ref' => $order->getRef()]);
				$action = 'approve';
			} elseif ($priced['total'] === 0) {
				$manageToken = $this->completePaidLocked($order, $actor === 'public' ? 'guest' : $actor);
				$action = 'done';
			} else {
				$this->setStatusLocked($order, [self::ST_CREATED], self::ST_PENDING_PAYMENT,
					$now->modify('+' . $this->settings->getInt('order_hold_min', 15) . ' minutes'));
				$action = 'pay';
			}

			$this->audit->log((int)$order->getId(), $offeringId, $actor, 'order.created', null, $order->getStatus(),
				['amount' => $priced['total'], 'test' => $isTest ? 1 : 0]);
			$this->emitOrderEvent('order.created', $order);

			$this->db->commit();
			return ['order' => $order, 'manageToken' => $manageToken, 'action' => $action];
		} catch (\Throwable $e) {
			$this->db->rollBack();
			if ($e instanceof DbException && $e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				// idem_token race: the other request won — return its order.
				$winner = $this->orders->findByIdemToken($idemToken);
				if ($winner !== null) {
					return ['order' => $winner, 'manageToken' => '', 'action' => 'idempotent-replay'];
				}
			}
			throw $e;
		}
	}

	// ---------------------------------------------------------------
	// Transitions (all CAS-guarded)
	// ---------------------------------------------------------------

	/** webhook-only truth. pending_payment|expired → paid. */
	public function markPaid(int $orderId, string $actor): Order
	{
		return $this->withLock($orderId, function (Order $order) use ($actor) {
			if ($order->getStatus() === self::ST_PAID) {
				return $order; // idempotent replay
			}
			if (!in_array($order->getStatus(), [self::ST_PENDING_PAYMENT, self::ST_EXPIRED, self::ST_APPROVED], true)) {
				throw new StateConflictException('Illegal transition to paid', 'ILLEGAL_TRANSITION');
			}
			$this->completePaidLocked($order, $actor);
			return $order;
		});
	}

	/** Payment failed / PSP cancel. Release holds, notify waitlist. */
	public function markFailed(int $orderId, string $actor): Order
	{
		return $this->withLock($orderId, function (Order $order) use ($actor) {
			if ($order->getStatus() === self::ST_FAILED) {
				return $order;
			}
			if ($order->getStatus() !== self::ST_PENDING_PAYMENT) {
				throw new StateConflictException('Illegal transition to failed', 'ILLEGAL_TRANSITION');
			}
			$this->releaseHoldsLocked($order);
			$this->restoreMoneyLocksLocked($order);
			$this->setStatusLocked($order, [self::ST_PENDING_PAYMENT], self::ST_FAILED);
			$this->audit->log((int)$order->getId(), (int)$order->getOfferingId(), $actor, 'order.failed',
				self::ST_PENDING_PAYMENT, self::ST_FAILED);
			$this->emitOrderEvent('order.failed', $order, self::ST_PENDING_PAYMENT);
			$this->email->queue('payment_failed', $order->getBuyerEmail(), $order->getBuyerLocale(),
				['order_ref' => $order->getRef()]);
			return $order;
		});
	}

	/** Cron expiry: pending_payment|approved|pending_approval → expired. */
	public function expire(Order $order, string $actor = 'cron'): bool
	{
		try {
			$this->withLock((int)$order->getId(), function (Order $o) use ($actor) {
				if (!in_array($o->getStatus(), [self::ST_PENDING_PAYMENT, self::ST_APPROVED, self::ST_PENDING_APPROVAL], true)) {
					return $o; // raced by webhook — leave it
				}
				$from = $o->getStatus();
				$this->releaseHoldsLocked($o);
				$this->restoreMoneyLocksLocked($o);
				$this->setStatusLocked($o, [$from], self::ST_EXPIRED);
				$this->audit->log((int)$o->getId(), (int)$o->getOfferingId(), $actor, 'order.expired', $from, self::ST_EXPIRED);
				$this->emitOrderEvent('order.expired', $o, $from);
				$this->email->queue('order_expired', $o->getBuyerEmail(), $o->getBuyerLocale(),
					['order_ref' => $o->getRef()]);
				return $o;
			});
			return true;
		} catch (StateConflictException $e) {
			// only a genuinely stolen transition is benign — an inventory
			// underflow or other conflict must surface loudly, not loop
			// silently every cron tick.
			if ($e->getCodeName() === 'ILLEGAL_TRANSITION') {
				return false;
			}
			throw $e;
		}
	}

	/** Seller approves an approval-required order → pay link sent. */
	public function approve(int $orderId, string $sellerUid): Order
	{
		return $this->withLock($orderId, function (Order $o) use ($sellerUid) {
			if ($o->getStatus() === self::ST_APPROVED) {
				return $o;
			}
			if ($o->getStatus() !== self::ST_PENDING_APPROVAL) {
				throw new StateConflictException('Order is not awaiting approval', 'ILLEGAL_TRANSITION');
			}
			$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
			$this->setStatusLocked($o, [self::ST_PENDING_APPROVAL], self::ST_APPROVED,
				$now->modify('+' . $this->settings->getInt('payment_ttl_min', 1440) . ' minutes'));
			$o->setApprovedBy($sellerUid);
			$o->setApprovedAt($now);
			$this->orders->update($o);
			$this->audit->log((int)$o->getId(), (int)$o->getOfferingId(), $sellerUid, 'order.approved',
				self::ST_PENDING_APPROVAL, self::ST_APPROVED);
			$this->emitOrderEvent('order.approved', $o, self::ST_PENDING_APPROVAL);
			// pay link goes out — fresh bearer; the earlier approval token
			// (delivered in the checkout response) is retired.
			[$tok, $hash] = $this->tokens->mint();
			$o->setManageTokenHash($hash);
			$this->orders->update($o);
			$this->email->queue('payment_link', $o->getBuyerEmail(), $o->getBuyerLocale(),
				['order_ref' => $o->getRef(),
				 'pay_url' => $this->url->linkToRouteAbsolute('bookingcheck.manage.show', ['token' => $tok])]);
			return $o;
		});
	}

	public function decline(int $orderId, string $sellerUid): Order
	{
		return $this->withLock($orderId, function (Order $o) use ($sellerUid) {
			if ($o->getStatus() === self::ST_DECLINED) {
				return $o;
			}
			if ($o->getStatus() !== self::ST_PENDING_APPROVAL) {
				throw new StateConflictException('Order is not awaiting approval', 'ILLEGAL_TRANSITION');
			}
			$this->releaseHoldsLocked($o);
			$this->restoreMoneyLocksLocked($o);
			$this->setStatusLocked($o, [self::ST_PENDING_APPROVAL], self::ST_DECLINED);
			$this->audit->log((int)$o->getId(), (int)$o->getOfferingId(), $sellerUid, 'order.declined',
				self::ST_PENDING_APPROVAL, self::ST_DECLINED);
			$this->emitOrderEvent('order.declined', $o, self::ST_PENDING_APPROVAL);
			$this->email->queue('order_declined', $o->getBuyerEmail(), $o->getBuyerLocale(),
				['order_ref' => $o->getRef()]);
			return $o;
		});
	}

	/**
	 * Release pre-payment holds (buyer aborts checkout). Only legal while
	 * the order has no money movement.
	 */
	public function cancelPending(int $orderId, string $actor): Order
	{
		return $this->withLock($orderId, function (Order $o) use ($actor) {
			if (in_array($o->getStatus(), self::TERMINAL, true)) {
				return $o;
			}
			if (!in_array($o->getStatus(), [self::ST_CREATED, self::ST_PENDING_PAYMENT, self::ST_PENDING_APPROVAL, self::ST_APPROVED], true)) {
				throw new StateConflictException('Order cannot be cancelled in this state', 'ILLEGAL_TRANSITION');
			}
			$from = $o->getStatus();
			$this->releaseHoldsLocked($o);
			$this->restoreMoneyLocksLocked($o);
			$this->setStatusLocked($o, [$from], self::ST_CANCELLED);
			$this->audit->log((int)$o->getId(), (int)$o->getOfferingId(), $actor, 'order.cancelled', $from, self::ST_CANCELLED);
			$this->emitOrderEvent('order.cancelled', $o, $from);
			return $o;
		});
	}

	/**
	 * Release sold seats after a settled cancellation/refund.
	 * Freed seats route through the waitlist offer path atomically.
	 */
	public function releaseSoldSeatsLocked(Order $order): void
	{
		$seatLines = [];
		foreach ($this->lines->findByOrder((int)$order->getId()) as $line) {
			if ($line->getLineKind() === 'seat') {
				$seatLines[] = [
					'target_type' => $line->getTargetType(),
					'target_id' => (int)$line->getTargetId(),
					'resource_id' => $line->getResourceId() !== null ? (int)$line->getResourceId() : null,
				];
			}
		}
		// locks before mutation, global order — the release path would
		// otherwise take inventory locks in insertion order (deadlock edge).
		$this->inventory->prelock($seatLines);
		foreach ($this->lines->findByOrder((int)$order->getId()) as $line) {
			if ($line->getLineKind() !== 'seat') {
				continue;
			}
			$this->inventory->releaseSold(
				$line->getTargetType(), (int)$line->getTargetId(), (int)$line->getSeats(),
				$line->getResourceId() !== null ? (int)$line->getResourceId() : null,
				fn (string $tt, int $tid) => $this->waitlist->offerSeat($tt, $tid),
			);
		}
	}

	public function transitionToCancelled(int $orderId, string $actor): Order
	{
		return $this->withLock($orderId, function (Order $o) use ($actor) {
			if ($o->getStatus() === self::ST_CANCELLED) {
				return $o;
			}
			if ($o->getStatus() !== self::ST_PAID) {
				throw new StateConflictException('Only paid orders can be cancelled', 'ILLEGAL_TRANSITION');
			}
			$this->releaseSoldSeatsLocked($o);
			$this->tickets->revokeForOrder((int)$o->getId());
			$this->setStatusLocked($o, [self::ST_PAID], self::ST_CANCELLED);
			$this->audit->log((int)$o->getId(), (int)$o->getOfferingId(), $actor, 'order.cancelled', self::ST_PAID, self::ST_CANCELLED);
			$this->emitOrderEvent('order.cancelled', $o, self::ST_PAID);
			return $o;
		});
	}

	/**
	 * @param int|null $creditAmountCents mint a GoBD credit note for this
	 *   amount inside the same transaction — allocate()'s FOR UPDATE only
	 *   serializes while a transaction is open, so the credit must live
	 *   here, never in an autocommit controller call.
	 */
	public function transitionToRefunded(int $orderId, string $actor, array $meta = [], ?int $creditAmountCents = null): Order
	{
		return $this->withLock($orderId, function (Order $o) use ($actor, $meta, $creditAmountCents) {
			if ($o->getStatus() === self::ST_REFUNDED) {
				return $o;
			}
			if (!in_array($o->getStatus(), [self::ST_PAID, self::ST_CANCELLED], true)) {
				throw new StateConflictException('Only paid/cancelled orders can be refunded', 'ILLEGAL_TRANSITION');
			}
			$from = $o->getStatus();
			if ($from === self::ST_PAID) {
				$this->releaseSoldSeatsLocked($o);
				$this->tickets->revokeForOrder((int)$o->getId());
			}
			$this->setStatusLocked($o, [$from], self::ST_REFUNDED);
			// stored value comes back: a voucher is money the buyer already
			// paid; refunding must return its credit (the use stays counted).
			if ($o->getVoucherId() !== null && (int)$o->getDiscountCents() > 0) {
				$this->vouchers->restoreCredit((int)$o->getVoucherId(), (int)$o->getDiscountCents());
			}
			if ($creditAmountCents !== null && $creditAmountCents > 0) {
				$this->invoices->issueCredit($o, $creditAmountCents);
			}
			$this->audit->log((int)$o->getId(), (int)$o->getOfferingId(), $actor, 'order.refunded', $from, self::ST_REFUNDED, $meta);
			$this->emitOrderEvent('order.refunded', $o, $from);
			return $o;
		});
	}

	/** expired → paid escape hatch: PSP authorized before redirect died. */
	public function reviveExpiredAsPaid(int $orderId, string $actor): Order
	{
		return $this->markPaid($orderId, $actor); // same CAS path; from-state covers expired
	}

	// ---------------------------------------------------------------
	// Internals
	// ---------------------------------------------------------------

	/**
	 * Fire a lifecycle event to subscribed outbound webhooks. Runs inside
	 * the order transaction — a rolled-back transition emits nothing.
	 * Payload carries no buyer PII: the ref is the correlation handle.
	 */
	private function emitOrderEvent(string $event, Order $o, ?string $from = null): void
	{
		$this->webhooks?->emit($event, [
			'order_ref' => $o->getRef(),
			'offering_id' => (int)$o->getOfferingId(),
			'from' => $from,
			'to' => $o->getStatus(),
			'amount_cents' => (int)$o->getAmountCents(),
			'currency' => $o->getCurrency(),
		]);
	}

	/** Run $fn with the order row locked in a fresh transaction. */
	private function withLock(int $orderId, callable $fn): Order
	{
		$this->db->beginTransaction();
		try {
			$order = $this->orders->findForUpdate($orderId);
			if ($order === null) {
				throw new NotFoundException();
			}
			$result = $fn($order);
			$this->db->commit();
			return $result;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/** CAS status write: fails when the row's status is not in $from. */
	private function setStatusLocked(Order $order, array $from, string $to, ?\DateTimeInterface $expiresAt = null): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_order')
			->set('status', $qb->createNamedParameter($to))
			->set('expires_at', $qb->createNamedParameter($expiresAt, 'datetime'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$order->getId())))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter($from, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
		if ($qb->executeStatement() !== 1) {
			throw new StateConflictException(sprintf('Transition %s→%s stolen', implode(',', $from), $to), 'ILLEGAL_TRANSITION');
		}
		$order->setStatus($to);
		$order->setExpiresAt($expiresAt);
	}

	/** pending_payment|approved → paid path: consume holds, invoice, tickets.
	 * Returns the fresh manage-link plaintext — the confirmation mail carries
	 * it, and the zero-total checkout response echoes it to the buyer. */
	private function completePaidLocked(Order $order, string $actor): string
	{
		$seatLines = [];
		$targets = [];
		foreach ($this->lines->findByOrder((int)$order->getId()) as $line) {
			if ($line->getLineKind() === 'seat') {
				$seatLines[] = $line;
				$targets[] = [
					'target_type' => $line->getTargetType(),
					'target_id' => (int)$line->getTargetId(),
					'resource_id' => $line->getResourceId() !== null ? (int)$line->getResourceId() : null,
				];
			}
		}
		$this->inventory->prelock($targets);
		foreach ($seatLines as $line) {
			$this->inventory->confirm($line->getTargetType(), (int)$line->getTargetId(),
				(int)$line->getSeats(), $line->getResourceId() !== null ? (int)$line->getResourceId() : null);
		}
		$wasApproved = $order->getStatus() === self::ST_APPROVED;
		$this->setStatusLocked($order,
			$wasApproved ? [self::ST_APPROVED] : [self::ST_PENDING_PAYMENT, self::ST_EXPIRED, self::ST_CREATED],
			self::ST_PAID);
		$order->setPaidAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
		$this->orders->update($order);

		if ((int)$order->getIsTest() !== 1) {
			$this->invoices->issueForOrder($order);
		}
		$this->tickets->issueForOrder($order);
		$off = ($this->crmSync !== null || $this->sellerNotify !== null)
			? $this->offerings->find((int)$order->getOfferingId()) : null;
		if ($this->crmSync !== null && $off !== null) {
			$seatCount = 0;
			foreach ($seatLines as $sl) {
				$seatCount += (int)$sl->getSeats();
			}
			$this->crmSync->enqueueBuyerSync($order, $seatCount, (string)$off->getTitle());
		}
		if ($this->sellerNotify !== null && $off !== null) {
			$this->sellerNotify->orderPaid($order, (string)$off->getCreatedBy(), (string)$off->getTitle());
		}

		// Fresh bearer for the buyer: the createOrder token for pay-orders was
		// never exposed, so rotating here is safe and keeps one live link.
		[$manageToken, $manageHash] = $this->tokens->mint();
		$order->setManageTokenHash($manageHash);
		$this->orders->update($order);
		$manageUrl = $this->url->linkToRouteAbsolute('bookingcheck.manage.show',
			['token' => $manageToken]);

		$this->audit->log((int)$order->getId(), (int)$order->getOfferingId(), $actor, 'order.paid',
			$wasApproved ? self::ST_APPROVED : self::ST_PENDING_PAYMENT, self::ST_PAID);
		$this->emitOrderEvent('order.paid', $order,
			$wasApproved ? self::ST_APPROVED : self::ST_PENDING_PAYMENT);
		$this->email->queue('order_confirmed', $order->getBuyerEmail(), $order->getBuyerLocale(),
			['order_ref' => $order->getRef(), 'manage_url' => $manageUrl]);
		return $manageToken;
	}

	private function releaseHoldsLocked(Order $order): void
	{
		$seatLines = [];
		foreach ($this->lines->findByOrder((int)$order->getId()) as $line) {
			if ($line->getLineKind() === 'seat') {
				$seatLines[] = [
					'target_type' => $line->getTargetType(),
					'target_id' => (int)$line->getTargetId(),
					'resource_id' => $line->getResourceId() !== null ? (int)$line->getResourceId() : null,
				];
			}
		}
		$this->inventory->prelock($seatLines);
		foreach ($this->lines->findByOrder((int)$order->getId()) as $line) {
			if ($line->getLineKind() !== 'seat') {
				continue;
			}
			$this->inventory->releaseHold($line->getTargetType(), (int)$line->getTargetId(),
				(int)$line->getSeats(), $line->getResourceId() !== null ? (int)$line->getResourceId() : null);
		}
	}

	/** Expiry/failure restores voucher credit + promo uses (§9.13). */
	private function restoreMoneyLocksLocked(Order $order): void
	{
		if ($order->getVoucherId() !== null && (int)$order->getDiscountCents() > 0) {
			$this->vouchers->restore((int)$order->getVoucherId(), (int)$order->getDiscountCents());
		}
		if ($order->getPromoId() !== null) {
			$this->promos->decrementUses((int)$order->getPromoId());
		}
	}

	/** Deterministic lock order: target_type ASC, then target_id ASC. */
	private function sortedSeatHolds(array $seatLines): array
	{
		$agg = [];
		foreach ($seatLines as $l) {
			$key = $l['target_type'] . ':' . (int)$l['target_id'] . ':' . (int)($l['resource_id'] ?? 0);
			if (!isset($agg[$key])) {
				$agg[$key] = [
					'target_type' => $l['target_type'], 'target_id' => (int)$l['target_id'],
					'resource_id' => $l['resource_id'] ?? null, 'seats' => 0,
				];
			}
			$agg[$key]['seats'] += (int)$l['seats'];
		}
		uasort($agg, fn ($a, $b) => [$a['target_type'], $a['target_id'], $a['resource_id'] ?? 0]
			<=> [$b['target_type'], $b['target_id'], $b['resource_id'] ?? 0]);
		return array_values($agg);
	}

	private function assertSalesWindowOpen($offering): void
	{
		$now = new \DateTimeImmutable();
		if ($offering->getSalesFrom() !== null && $offering->getSalesFrom() > $now) {
			throw new StateConflictException('Sales have not opened yet', 'POLICY_WINDOW_CLOSED');
		}
		if ($offering->getSalesTo() !== null && $offering->getSalesTo() < $now) {
			throw new StateConflictException('Sales are closed', 'POLICY_WINDOW_CLOSED');
		}
	}

	private function assertApprovalCap($offering, array $seatLines): void
	{
		$cap = $offering->getApprovalHoldCap();
		if ($cap === null) {
			return;
		}
		$requested = array_sum(array_map(fn ($l) => (int)$l['seats'], $seatLines));
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->sum('l.seats'))
			->from('bc_order_line', 'l')
			->innerJoin('l', 'bc_order', 'o', $qb->expr()->eq('l.order_id', 'o.id'))
			->where($qb->expr()->eq('o.offering_id', $qb->createNamedParameter((int)$offering->getId())))
			->andWhere($qb->expr()->eq('o.status', $qb->createNamedParameter(self::ST_PENDING_APPROVAL)))
			->andWhere($qb->expr()->eq('l.line_kind', $qb->createNamedParameter('seat')));
		$held = (int)$qb->executeQuery()->fetchOne();
		if ($held + $requested > (int)$cap) {
			throw new CapacityException('Approval queue is full — try again later');
		}
	}

	private function validateBuyer(string $name, string $email, string $locale): void
	{
		$fields = [];
		if (mb_strlen(trim($name)) < 2 || mb_strlen($name) > 190) {
			$fields['buyer_name'] = 'Enter your name.';
		}
		if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
			$fields['buyer_email'] = 'Enter a valid email address.';
		}
		if (!preg_match('/^[a-z]{2}(-[a-zA-Z]{2,8})?$/', $locale)) {
			$fields['locale'] = 'Invalid locale.';
		}
		if ($fields !== []) {
			throw new ValidationException($fields);
		}
	}
}

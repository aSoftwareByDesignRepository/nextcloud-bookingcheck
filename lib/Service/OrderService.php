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
use OCA\BookingCheck\Support\Locales;
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
		private readonly \OCA\BookingCheck\Db\PaymentMapper $payments,
		private readonly IURLGenerator $url,
		private readonly ?CrmSyncService $crmSync = null,
		private readonly ?SellerNotifyService $sellerNotify = null,
		private readonly ?OutboundWebhookService $webhooks = null,
		private readonly ?PushService $push = null,
		private readonly ?PassService $passes = null,
		private readonly ?LicenseService $license = null,
		private readonly ?\OCA\BookingCheck\Db\EventMapper $events = null,
	) {
	}

	// ---------------------------------------------------------------
	// Creation — the checkout spine
	// ---------------------------------------------------------------

	/**
	 * @param list<array{target_type:string,target_id:int,seats:int,price_cat_id?:int|null,resource_id?:int|null}> $seatLines
	 * @param list<array{addon_id:int,qty:int}> $addonLines
	 * @param array{company:string|null,country:string|null,vat_id:string|null,vies_status:string,reverse_charge:bool}|null $billing
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
		?string $payMethod = null,
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
				'dyn_bp' => $offering->getDynBp(),
				'dyn_step_pct' => $offering->getDynStepPct(),
			];
			$priced = $this->pricing->price($offeringRow, $seatLines, $addonLines,
				$promoCode, $voucherCode, !empty($billing['reverse_charge']),
				is_array($billing) ? ($billing['country'] ?? null) : null);

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
			$order->setBuyerLocale(Locales::canonicalize($locale));
			$order->setAmountCents($priced['total']);
			$order->setCurrency($priced['currency']);
			// the resolved (OSS-aware) rate is a fact of THIS order — invoices
			// must split at it, not at the offering's current mutable rate
			$order->setVatBp($priced['vatBp']);
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
			// NH-18/19/27 — payment method + receivable remainder
			$depositBp = (int)$offering->getDepositBp();
			$wantsInvoice = $payMethod === 'invoice';
			if ($wantsInvoice && (int)$offering->getInvoicePay() !== 1) {
				throw new ValidationException(['pay_method' => 'Payment by invoice is not offered here.']);
			}
			if ($wantsInvoice && $priced['total'] > 0) {
				$order->setPayMethod('invoice');
				$order->setRemainderCents($priced['total']);
			} elseif ($depositBp > 0 && $depositBp < 10000 && $priced['total'] > 0) {
				$charge = intdiv($priced['total'] * $depositBp, 10000);
				$order->setPayMethod('deposit');
				$order->setRemainderCents($priced['total'] - $charge);
			} else {
				$order->setPayMethod('psp');
				$order->setRemainderCents(0);
			}
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
			} elseif ($order->getPayMethod() === 'invoice') {
				// NH-19 — the invoice IS the payment claim (receivable);
				// tickets + GoBD invoice issue now, seller reconciles later
				$pay = new \OCA\BookingCheck\Db\Payment();
				$pay->setOrderId((int)$order->getId());
				$pay->setProvider('invoice');
				$pay->setStatus('open');
				$pay->setAmountCents($priced['total']);
				$pay->setCreatedAt($now);
				$this->payments->insert($pay);
				$manageToken = $this->completePaidLocked($order, $actor === 'public' ? 'guest' : $actor);
				$action = 'done';
			} else {
				if ((int)$order->getRemainderCents() > 0) {
					// deposit: the tranche goes through PSP; the remainder is
					// a tracked receivable the seller settles via
					// markRemainderReceived (provider='invoice' row)
					$pay = new \OCA\BookingCheck\Db\Payment();
					$pay->setOrderId((int)$order->getId());
					$pay->setProvider('invoice');
					$pay->setStatus('open');
					$pay->setAmountCents((int)$order->getRemainderCents());
					$pay->setCreatedAt($now);
					$this->payments->insert($pay);
				}
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

	/**
	 * NH-36 — multi-offering cart: one order, one payment, lines grouped
	 * across offerings. offering_id stores the FIRST offering (lead);
	 * every line still carries its own target so per-offering attribution
	 * stays derivable and roster/entitlement queries keep working.
	 *
	 * Rules:
	 *  - approval required on ANY offering ⇒ whole cart needs approval
	 *  - terms on ANY offering ⇒ consent required; texts are concatenated
	 *    onto the order verbatim (never retro-rewritten)
	 *  - invoice-pay ⇒ only when EVERY offering in the cart allows it
	 *  - deposit_bp ⇒ evaluated on the lead offering, applied to the sum
	 *  - voucher ⇒ applied once to the combined charge basis (NN-26 CAS)
	 *  - promos ⇒ per-offering map, scoped+windowed as usual
	 *
	 * @param list<array{offering_id:int,lines:list<array{target_type:string,target_id:int,seats:int,price_cat_id?:int|null,resource_id?:int|null}>,addons:list<array{addon_id:int,qty:int}>}> $groups
	 * @param array<int,string|null> $promosByOffering promo code per offering_id
	 * @param array{company:string|null,country:string|null,vat_id:string|null,vies_status:string,reverse_charge:bool}|null $billing
	 */
	public function createCartOrder(
		array $groups,
		string $buyerName,
		string $buyerEmail,
		string $locale,
		array $promosByOffering,
		?string $voucherCode,
		string $idemToken,
		?array $billing,
		string $actor,
		bool $consented = false,
		?string $payMethod = null,
	): array {
		if (count($groups) < 1 || count($groups) > 10) {
			throw new ValidationException(['cart' => 'Cart must contain between 1 and 10 offerings.']);
		}
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
			$allLines = [];
			$allHolds = [];
			$promoCents = 0;
			$needsApproval = false;
			$tosTexts = [];
			$invoiceOk = true;
			$leadOffering = null;
			$currency = null;
			$vatBp = null;
			$chargeBasis = 0;
			$reverseCharge = !empty($billing['reverse_charge']);
			$offeringIds = [];

			// canonical lock order (offering → inventory → waitlist): all
			// offerings locked in id order BEFORE the business pass —
			// concurrent carts {A,B} and {B,A} would otherwise deadlock on
			// FOR UPDATE. Processing still runs in cart order so the lead
			// offering keeps its meaning.
			$lockIds = [];
			foreach ($groups as $g) {
				$lockIds[(int)($g['offering_id'] ?? 0)] = true;
			}
			$lockIds = array_map('intval', array_keys($lockIds));
			sort($lockIds);
			$locked = [];
			foreach ($lockIds as $lid) {
				// invalid ids stay unmapped — the group pass below converts
				// them into the same ValidationException/404 contract as before
				$locked[$lid] = $lid > 0 ? $this->offerings->findForUpdate($lid) : null;
			}

			foreach ($groups as $g) {
				$offeringId = (int)($g['offering_id'] ?? 0);
				if ($offeringId <= 0 || in_array($offeringId, $offeringIds, true)) {
					throw new ValidationException(['cart' => 'Invalid cart group.']);
				}
				$offeringIds[] = $offeringId;
				$offering = $locked[$offeringId] ?? null;
				if ($offering === null || $offering->getStatus() !== 'published') {
					throw new NotFoundException();
				}
				$this->assertSalesWindowOpen($offering);
				if ((int)$offering->getInvoicePay() !== 1) {
					$invoiceOk = false;
				}
				$tos = trim((string)($offering->getTosText() ?? ''));
				if ($tos !== '') {
					$tosTexts[] = $tos;
				}
				$seatLines = $g['lines'] ?? [];
				$addonLines = $g['addons'] ?? [];
				$this->inventory->assertTargetsOwnedBy($offeringId, $seatLines);
				$offeringRow = [
					'id' => $offeringId,
					'price_cents' => $offering->getPriceCents(),
					'currency' => $offering->getCurrency(),
					'vat_bp' => $offering->getVatBp(),
					'dyn_bp' => $offering->getDynBp(),
					'dyn_step_pct' => $offering->getDynStepPct(),
				];
				$priced = $this->pricing->price($offeringRow, $seatLines, $addonLines,
					$promosByOffering[$offeringId] ?? null, null,
					!empty($billing['reverse_charge']),
					is_array($billing) ? ($billing['country'] ?? null) : null);
				if ($currency === null) {
					$currency = $priced['currency'];
				} elseif ($currency !== $priced['currency']) {
					throw new ValidationException(['cart' => 'All cart items must share one currency.']);
				}
				// the invoice model stores ONE net/vat pair — a taxable cart
				// mixing resolved VAT rates could not produce a correct
				// document, so it is refused like mixed currencies.
				// (reverse-charge carts stay allowed: their document reports
				// vat=0 and the per-group net already handles mixed rates —
				// a mixed RC cart just stores -1 for "no single rate")
				if ($vatBp === null) {
					$vatBp = $priced['vatBp'];
				} elseif ($vatBp !== $priced['vatBp']) {
					if ($reverseCharge) {
						$vatBp = -1;
					} else {
						throw new ValidationException(['cart' => 'All cart items must share one VAT rate.']);
					}
				}
				// per-group charge basis: gross for domestic/OSS buyers, NET
				// for reverse-charge — summing gross components would charge
				// a §13b buyer the VAT they legally do not owe
				$chargeBasis += $priced['total'];
				$promoCents += $priced['promoCents'];
				if ($priced['promoId'] !== null) {
					if (!$this->promos->incrementUses($priced['promoId'])) {
						throw new ValidationException(['promo' => 'Promo code could not be applied.']);
					}
				}
				foreach ($priced['lines'] as $l) {
					$allLines[] = $l;
					if ($l['line_kind'] === 'seat') {
						$allHolds[] = [
							'target_type' => $l['target_type'],
							'target_id' => $l['target_id'],
							'seats' => $l['seats'],
							'resource_id' => $l['resource_id'] ?? null,
						];
					}
				}
				if ((int)$offering->getApprovalRequired() === 1) {
					$needsApproval = true;
					$this->assertApprovalCap($offering, $seatLines);
				}
				if ($leadOffering === null) {
					$leadOffering = $offering;
				}
			}

			if ($tosTexts !== [] && !$consented) {
				throw new ValidationException(['consent' => 'Please accept the terms to continue.']);
			}

			// aggregate voucher (single redemption across the cart)
			$voucherId = null;
			$voucherCents = 0;
			if ($voucherCode !== null && $voucherCode !== '' && ($this->license === null || $this->license->hasFeature('vouchers'))) {
				$v = $this->vouchers->findByCode($voucherCode);
				if ($v !== null && (int)$v->getActive() === 1
					&& (int)$v->getRemainingCents() > 0
					&& (int)$v->getUses() < (int)$v->getMaxUses()
					&& ($v->getCurrency() === null || $v->getCurrency() === $currency)
					&& ($v->getValidUntil() === null || $v->getValidUntil() >= new \DateTimeImmutable())) {
					$voucherId = (int)$v->getId();
					$voucherCents = min((int)$v->getRemainingCents(), $chargeBasis);
				}
			}
			$total = max(0, $chargeBasis - $voucherCents);

			[$manageToken, $manageHash] = $this->tokens->mint();
			$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
			$order = new Order();
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
			$order->setOfferingId((int)$leadOffering->getId());
			$order->setBuyerName($buyerName);
			$order->setBuyerEmail(strtolower(trim($buyerEmail)));
			$order->setBuyerLocale(Locales::canonicalize($locale));
			$order->setAmountCents($total);
			$order->setCurrency((string)$currency);
			// resolved rates matched across groups (checked above); stored so
			// invoices split at the priced rate, not a mutable offering rate.
			// -1 = no single resolved rate (reverse-charge cart: the document
			// reports vat=0 and never consults this field anyway)
			$order->setVatBp($vatBp ?? -1);
			$order->setIdemToken($idemToken);
			$order->setManageTokenHash($manageHash);
			$order->setIsTest(0);
			$order->setVoucherId($voucherId);
			$order->setDiscountCents($voucherCents);
			$order->setPromoCents($promoCents);
			$order->setCreatedAt($now);
			$order->setStatus(self::ST_CREATED);
			if ($billing !== null) {
				$order->setBuyerCompany($billing['company'] ?? null);
				$order->setBillingCountry($billing['country'] ?? null);
				$order->setVatId($billing['vat_id'] ?? null);
				$order->setReverseCharge($reverseCharge ? 1 : 0);
				$order->setViesStatus($billing['vies_status'] ?? 'none');
			}
			if ($tosTexts !== []) {
				$order->setConsentAt($now);
				$order->setConsentText(implode("\n\n", array_slice(array_unique($tosTexts), 0, 5)));
			}
			$wantsInvoice = $payMethod === 'invoice';
			if ($wantsInvoice) {
				if (!$invoiceOk || $total <= 0) {
					throw new ValidationException(['pay_method' => 'Payment by invoice is not available for this cart.']);
				}
				$order->setPayMethod('invoice');
				$order->setRemainderCents($total);
			} else {
				$depositBp = (int)$leadOffering->getDepositBp();
				if ($depositBp > 0 && $depositBp < 10000 && $total > 0) {
					$charge = intdiv($total * $depositBp, 10000);
					$order->setPayMethod('deposit');
					$order->setRemainderCents($total - $charge);
				} else {
					$order->setPayMethod('psp');
					$order->setRemainderCents(0);
				}
			}
			$this->orders->insert($order);

			// global deterministic hold order — cross-offering deadlock safe
			$this->inventory->prelock($allHolds);
			foreach ($this->sortedSeatHolds($allHolds) as $h) {
				$this->inventory->hold($h['target_type'], $h['target_id'], $h['seats'], $h['resource_id'] ?? null);
			}
			if ($voucherId !== null && $voucherCents > 0
				&& !$this->vouchers->redeem($voucherId, $voucherCents)) {
				throw new ValidationException(['voucher' => 'Voucher could not be applied.']);
			}
			foreach ($allLines as $l) {
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

			if ($needsApproval) {
				$this->setStatusLocked($order, [self::ST_CREATED], self::ST_PENDING_APPROVAL,
					$now->modify('+' . $this->settings->getInt('approval_hold_min', 4320) . ' minutes'));
				$this->email->queue('approval_request', $this->settings->get('seller_email'), 'en',
					['order_ref' => $order->getRef(), 'offering' => $leadOffering->getTitle()]);
				$this->email->queue('approval_pending', $order->getBuyerEmail(), $order->getBuyerLocale(),
					['order_ref' => $order->getRef()]);
				$action = 'approve';
			} elseif ($total === 0) {
				$manageToken = $this->completePaidLocked($order, $actor === 'public' ? 'guest' : $actor);
				$action = 'done';
			} elseif ($order->getPayMethod() === 'invoice') {
				$pay = new \OCA\BookingCheck\Db\Payment();
				$pay->setOrderId((int)$order->getId());
				$pay->setProvider('invoice');
				$pay->setStatus('open');
				$pay->setAmountCents($total);
				$pay->setCreatedAt($now);
				$this->payments->insert($pay);
				$manageToken = $this->completePaidLocked($order, $actor === 'public' ? 'guest' : $actor);
				$action = 'done';
			} else {
				if ((int)$order->getRemainderCents() > 0) {
					// deposit: the tranche goes through PSP; the remainder is
					// a tracked receivable the seller settles via
					// markRemainderReceived (provider='invoice' row)
					$pay = new \OCA\BookingCheck\Db\Payment();
					$pay->setOrderId((int)$order->getId());
					$pay->setProvider('invoice');
					$pay->setStatus('open');
					$pay->setAmountCents((int)$order->getRemainderCents());
					$pay->setCreatedAt($now);
					$this->payments->insert($pay);
				}
				$this->setStatusLocked($order, [self::ST_CREATED], self::ST_PENDING_PAYMENT,
					$now->modify('+' . $this->settings->getInt('order_hold_min', 15) . ' minutes'));
				$action = 'pay';
			}

			$this->audit->log((int)$order->getId(), (int)$leadOffering->getId(), $actor, 'order.created',
				null, $order->getStatus(), ['amount' => $total, 'cart' => count($groups)]);
			$this->emitOrderEvent('order.created', $order);
			$this->db->commit();
			return ['order' => $order, 'manageToken' => $manageToken, 'action' => $action];
		} catch (\Throwable $e) {
			$this->db->rollBack();
			if ($e instanceof DbException && $e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				$winner = $this->orders->findByIdemToken($idemToken);
				if ($winner !== null) {
					return ['order' => $winner, 'manageToken' => '', 'action' => 'idempotent-replay'];
				}
			}
			throw $e;
		}
	}

	/**
	 * NH-28 — pass/membership checkout: qty × flat price, no targets,
	 * no holds, no approval. Paid mints N bearer passes in
	 * completePaidLocked. Same money rails as seats (promo/voucher/
	 * deposit/invoice) — the only thing missing is inventory.
	 * @param array{company:string|null,country:string|null,vat_id:string|null,vies_status:string,reverse_charge:bool}|null $billing
	 */
	public function createPassOrder(
		int $offeringId,
		string $buyerName,
		string $buyerEmail,
		string $locale,
		int $passQty,
		?string $promoCode,
		?string $voucherCode,
		string $idemToken,
		?array $billing,
		string $actor,
		bool $consented = false,
		?string $payMethod = null,
	): array {
		$existing = $this->orders->findByIdemToken($idemToken);
		if ($existing !== null) {
			return ['order' => $existing, 'manageToken' => '', 'action' => 'idempotent-replay'];
		}
		if (!preg_match('/^[A-Za-z0-9_-]{8,36}$/', $idemToken)) {
			throw new ValidationException(['idem_token' => 'Invalid idempotency token.']);
		}
		$this->validateBuyer($buyerName, $buyerEmail, $locale);
		if ($passQty < 1 || $passQty > 50) {
			throw new ValidationException(['pass_qty' => 'Invalid quantity.']);
		}

		$this->db->beginTransaction();
		try {
			$offering = $this->offerings->findForUpdate($offeringId);
			if ($offering === null || $offering->getStatus() !== 'published'
				|| $offering->getType() !== 'pass') {
				throw new NotFoundException();
			}
			$this->assertSalesWindowOpen($offering);
			$tosText = trim((string)($offering->getTosText() ?? ''));
			if ($tosText !== '' && !$consented) {
				throw new ValidationException(['consent' => 'Please accept the terms to continue.']);
			}
			$priced = $this->pricing->price([
				'id' => $offeringId,
				'price_cents' => $offering->getPriceCents(),
				'currency' => $offering->getCurrency(),
				'vat_bp' => $offering->getVatBp(),
				'dyn_bp' => 0, 'dyn_step_pct' => 0,
			], [], [], $promoCode, $voucherCode,
				!empty($billing['reverse_charge']),
				is_array($billing) ? ($billing['country'] ?? null) : null,
				$passQty);
			if ($priced['promoId'] !== null
				&& !$this->promos->incrementUses($priced['promoId'])) {
				throw new ValidationException(['promo' => 'Promo code could not be applied.']);
			}

			[$manageToken, $manageHash] = $this->tokens->mint();
			$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
			$order = new Order();
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
			$order->setBuyerLocale(Locales::canonicalize($locale));
			$order->setAmountCents($priced['total']);
			$order->setCurrency($priced['currency']);
			$order->setVatBp($priced['vatBp']);
			$order->setIdemToken($idemToken);
			$order->setManageTokenHash($manageHash);
			$order->setIsTest(0);
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
			$wantsInvoice = $payMethod === 'invoice';
			if ($wantsInvoice && (int)$offering->getInvoicePay() !== 1) {
				throw new ValidationException(['pay_method' => 'Payment by invoice is not offered here.']);
			}
			if ($wantsInvoice && $priced['total'] > 0) {
				$order->setPayMethod('invoice');
				$order->setRemainderCents($priced['total']);
			} elseif ((int)$offering->getDepositBp() > 0 && (int)$offering->getDepositBp() < 10000
				&& $priced['total'] > 0) {
				$charge = intdiv($priced['total'] * (int)$offering->getDepositBp(), 10000);
				$order->setPayMethod('deposit');
				$order->setRemainderCents($priced['total'] - $charge);
			} else {
				$order->setPayMethod('psp');
				$order->setRemainderCents(0);
			}
			$this->orders->insert($order);

			if ($priced['voucherId'] !== null && $priced['voucherCents'] > 0
				&& !$this->vouchers->redeem($priced['voucherId'], $priced['voucherCents'])) {
				throw new ValidationException(['voucher' => 'Voucher could not be applied.']);
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
			if ($priced['total'] === 0) {
				$manageToken = $this->completePaidLocked($order, $actor === 'public' ? 'guest' : $actor);
				$action = 'done';
			} elseif ($order->getPayMethod() === 'invoice') {
				$pay = new \OCA\BookingCheck\Db\Payment();
				$pay->setOrderId((int)$order->getId());
				$pay->setProvider('invoice');
				$pay->setStatus('open');
				$pay->setAmountCents($priced['total']);
				$pay->setCreatedAt($now);
				$this->payments->insert($pay);
				$manageToken = $this->completePaidLocked($order, $actor === 'public' ? 'guest' : $actor);
				$action = 'done';
			} else {
				if ((int)$order->getRemainderCents() > 0) {
					// deposit: the tranche goes through PSP; the remainder is
					// a tracked receivable the seller settles via
					// markRemainderReceived (provider='invoice' row)
					$pay = new \OCA\BookingCheck\Db\Payment();
					$pay->setOrderId((int)$order->getId());
					$pay->setProvider('invoice');
					$pay->setStatus('open');
					$pay->setAmountCents((int)$order->getRemainderCents());
					$pay->setCreatedAt($now);
					$this->payments->insert($pay);
				}
				$this->setStatusLocked($order, [self::ST_CREATED], self::ST_PENDING_PAYMENT,
					$now->modify('+' . $this->settings->getInt('order_hold_min', 15) . ' minutes'));
				$action = 'pay';
			}
			$this->audit->log((int)$order->getId(), $offeringId, $actor, 'order.created',
				null, $order->getStatus(), ['amount' => $priced['total'], 'passes' => $passQty]);
			$this->emitOrderEvent('order.created', $order);
			$this->db->commit();
			return ['order' => $order, 'manageToken' => $manageToken, 'action' => $action];
		} catch (\Throwable $e) {
			$this->db->rollBack();
			if ($e instanceof DbException && $e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				$winner = $this->orders->findByIdemToken($idemToken);
				if ($winner !== null) {
					return ['order' => $winner, 'manageToken' => '', 'action' => 'idempotent-replay'];
				}
			}
			throw $e;
		}
	}

	/**
	 * NH-12 — door walk-up sale: instant paid order, hold+confirm in one
	 * transaction, no buyer contact, no email, no manage link exposure.
	 * Idempotent via idem_token (device retries must not double-sell).
	 */
	public function createWalkupOrder(
		int $offeringId,
		string $targetType,
		int $targetId,
		int $seats,
		string $actor,
		?string $idemToken = null,
	): array {
		$idemToken = $idemToken ?? ('wu-' . bin2hex(random_bytes(16)));
		$existing = $this->orders->findByIdemToken($idemToken);
		if ($existing !== null) {
			return ['order' => $existing, 'manageToken' => '', 'action' => 'idempotent-replay'];
		}
		if ($seats < 1 || $seats > 50) {
			throw new ValidationException(['seats' => 'Invalid seat count.']);
		}
		$this->db->beginTransaction();
		try {
			$offering = $this->offerings->findForUpdate($offeringId);
			if ($offering === null || $offering->getStatus() !== 'published') {
				throw new NotFoundException();
			}
			$seatLines = [['target_type' => $targetType, 'target_id' => $targetId, 'seats' => $seats]];
			$this->inventory->assertTargetsOwnedBy($offeringId, $seatLines);
			$this->inventory->prelock($seatLines);
			$priced = $this->pricing->price([
				'id' => $offeringId,
				'price_cents' => $offering->getPriceCents(),
				'currency' => $offering->getCurrency(),
				'vat_bp' => $offering->getVatBp(),
				'dyn_bp' => $offering->getDynBp(),
				'dyn_step_pct' => $offering->getDynStepPct(),
			], $seatLines);

			$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
			$order = new Order();
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
			$order->setBuyerName('Walk-up guest');
			$order->setBuyerEmail('');
			$order->setBuyerLocale('en');
			$order->setAmountCents($priced['total']);
			$order->setCurrency($priced['currency']);
			$order->setVatBp($priced['vatBp']);
			$order->setIdemToken($idemToken);
			[, $manageHash] = $this->tokens->mint(); // token never leaves the door
			$order->setManageTokenHash($manageHash);
			$order->setIsTest(0);
			$order->setPayMethod('walkup');
			$order->setRemainderCents(0);
			$order->setCreatedAt($now);
			$order->setStatus(self::ST_CREATED);
			$this->orders->insert($order);

			foreach ($this->sortedSeatHolds($seatLines) as $h) {
				$this->inventory->hold($h['target_type'], $h['target_id'], $h['seats'], $h['resource_id'] ?? null);
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
			$this->emitOrderEvent('order.created', $order);
			$this->completePaidLocked($order, $actor);
			$this->audit->log((int)$order->getId(), $offeringId, $actor, 'order.walkup',
				null, $order->getStatus(), ['amount' => $priced['total'], 'seats' => $seats]);
			$this->db->commit();
			return ['order' => $order, 'manageToken' => '', 'action' => 'done'];
		} catch (\Throwable $e) {
			$this->db->rollBack();
			if ($e instanceof DbException && $e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				// idem_token race: the concurrent request won — replay its
				// sale instead of reporting failure for a committed order
				$winner = $this->orders->findByIdemToken($idemToken);
				if ($winner !== null) {
					return ['order' => $winner, 'manageToken' => '', 'action' => 'idempotent-replay'];
				}
			}
			throw $e;
		}
	}

	/**
	 * Companion write API — create a minimal published event offering +
	 * its single occurrence in one transaction.
	 * @return array{0:int,1:int} [offeringId, eventId]
	 */
	public function createQuickOffering(
		string $title,
		int $priceCents,
		int $capacity,
		\DateTimeImmutable $startsAt,
		int $durationMin,
		string $actor,
	): array {
		if ($this->events === null) {
			throw new StateConflictException('Quick-offering writes unavailable', 'UNSUPPORTED');
		}
		$this->db->beginTransaction();
		try {
			$slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $title), '-'));
			if ($slug === '') {
				$slug = 'offering';
			}
			$slug = substr($slug . '-' . bin2hex(random_bytes(4)), 0, 190);
			$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
			$o = new \OCA\BookingCheck\Db\Offering();
			$o->setSlug($slug);
			$o->setTitle($title);
			$o->setType('event');
			$o->setPriceCents($priceCents);
			$o->setCurrency('EUR');
			$o->setVatBp(0);
			$o->setStatus('published');
			$o->setApprovalRequired(0);
			$o->setBookSeries(0);
			$o->setListInDirectory(0);
			$o->setWaitlistMode('none');
			$o->setCreatedBy($actor);
			$o->setCreatedAt($now);
			$o->setUpdatedAt($now);
			$this->offerings->insert($o);

			$ev = new \OCA\BookingCheck\Db\Event();
			$ev->setOfferingId((int)$o->getId());
			$ev->setStartsAt($startsAt);
			$ev->setEndsAt($startsAt->modify('+' . $durationMin . ' minutes'));
			$ev->setCapacity($capacity);
			$ev->setSold(0);
			$ev->setHeld(0);
			$ev->setStatus('scheduled');
			$this->events->insert($ev);
			$this->audit->log(null, (int)$o->getId(), $actor, 'offering.created', null, 'published');
			$this->db->commit();
			return [(int)$o->getId(), (int)$ev->getId()];
		} catch (\Throwable $e) {
			$this->db->rollBack();
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
				fn (string $tt, int $tid, ?int $rid = null) => $this->waitlist->offerSeat($tt, $tid, $rid),
			);
		}
	}

	/** NH-18/19: seller marks an open receivable (invoice/deposit) as received. */
	public function markRemainderReceived(int $orderId, string $actor, ?int $cents = null): Order
	{
		return $this->withLock($orderId, function (Order $order) use ($orderId, $actor, $cents) {
			if ($order->getStatus() !== self::ST_PAID) {
				throw new StateConflictException('Order is not paid', 'ILLEGAL_TRANSITION');
			}
			$open = (int)$order->getRemainderCents();
			if ($open <= 0) {
				throw new StateConflictException('No open remainder', 'ILLEGAL_TRANSITION');
			}
			if ($cents !== null && $cents !== $open) {
				throw new ValidationException(['amount' => 'Amount does not match the open remainder.']);
			}
			$order->setRemainderCents(0);
			$this->orders->update($order);
			$qb = $this->db->getQueryBuilder();
			$qb->update('bc_payment')->set('status', $qb->createNamedParameter('paid'))
				->where($qb->expr()->eq('order_id', $qb->createNamedParameter($orderId)))
				->andWhere($qb->expr()->eq('provider', $qb->createNamedParameter('invoice')))
				->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('open')));
			$qb->executeStatement();
			$this->audit->log($orderId, (int)$order->getOfferingId(), $actor, 'order.remainder_received',
				null, null, ['cents' => $open]);
			return $order;
		});
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
			if ($this->passes !== null) {
				$this->passes->revokeForOrder((int)$o->getId());
			}
			if ($this->push !== null) {
				$this->push->notifyManagers('order.cancelled', ['order_ref' => $o->getRef()]);
			}
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
	/** @param array<string,mixed> $meta */
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
	/** @param list<string> $from */
	private function setStatusLocked(Order $order, array $from, string $to, ?\DateTimeImmutable $expiresAt = null): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_order')
			->set('status', $qb->createNamedParameter($to))
			->set('expires_at', $qb->createNamedParameter($expiresAt, 'datetime'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$order->getId())))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter($from, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
		if ($qb->executeStatement() !== 1) {
			throw new StateConflictException('This state changed concurrently — please reload and retry.', 'ILLEGAL_TRANSITION');
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
		$off = ($this->crmSync !== null || $this->sellerNotify !== null || $this->passes !== null)
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
		if ($order->getBuyerEmail() !== '') {
			$this->email->queue('order_confirmed', $order->getBuyerEmail(), $order->getBuyerLocale(),
				['order_ref' => $order->getRef(), 'manage_url' => $manageUrl]);
		}
		// NH-28: pass offerings mint bearer passes on payment
		if ($this->passes !== null && $off !== null && $off->getType() === 'pass') {
			$this->passes->issueForOrder($order);
		}
		// NH-21: courtesy push to manager devices (never authoritative)
		if ($this->push !== null) {
			$this->push->notifyManagers('order.paid', ['order_ref' => $order->getRef()]);
		}
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
			// freed held seats go through the same waitlist offer path as
			// sold seats — expiry/payment-failure releases must notify too
			$this->inventory->releaseHoldWithOffer(
				$line->getTargetType(), (int)$line->getTargetId(), (int)$line->getSeats(),
				$line->getResourceId() !== null ? (int)$line->getResourceId() : null,
				fn (string $tt, int $tid, ?int $rid = null) => $this->waitlist->offerSeat($tt, $tid, $rid),
			);
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
	/** @param list<array{target_type:string,target_id:int,resource_id?:int|null,seats:int}> $seatLines */
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

	private function assertSalesWindowOpen(\OCA\BookingCheck\Db\Offering $offering): void
	{
		$now = new \DateTimeImmutable();
		if ($offering->getSalesFrom() !== null && $offering->getSalesFrom() > $now) {
			throw new StateConflictException('Sales have not opened yet', 'POLICY_WINDOW_CLOSED');
		}
		if ($offering->getSalesTo() !== null && $offering->getSalesTo() < $now) {
			throw new StateConflictException('Sales are closed', 'POLICY_WINDOW_CLOSED');
		}
	}

	/** @param list<array{seats:int}> $seatLines */
	private function assertApprovalCap(\OCA\BookingCheck\Db\Offering $offering, array $seatLines): void
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

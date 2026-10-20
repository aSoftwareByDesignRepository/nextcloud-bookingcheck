<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\InvoiceMapper;
use OCA\BookingCheck\Db\OrderMapper;
use OCA\BookingCheck\Db\OrderLineMapper;
use OCA\BookingCheck\Db\PaymentMapper;
use OCA\BookingCheck\Db\TicketMapper;
use OCA\BookingCheck\Exception\NotFoundException;
use OCA\BookingCheck\Exception\StateConflictException;
use OCA\BookingCheck\Exception\ValidationException;
use OCA\BookingCheck\Psp\PspManager;
use OCA\BookingCheck\Service\OrderService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;

class OrderController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly OrderService $orders,
		private readonly OrderMapper $orderMapper,
		private readonly OrderLineMapper $lines,
		private readonly PaymentMapper $payments,
		private readonly TicketMapper $tickets,
		private readonly InvoiceMapper $invoices,
		private readonly PspManager $psp,
		private readonly IUserSession $userSession,
		private readonly IDBConnection $db,
		private readonly \OCA\BookingCheck\Service\TokenService $tokenService,
		private readonly \OCP\IURLGenerator $urlGenerator,
		private readonly \OCA\BookingCheck\Service\EmailService $emailService,
		private readonly \OCA\BookingCheck\Service\AuditService $auditService,
		private readonly ?\OCA\BookingCheck\Db\AbuseReportMapper $abuseReports = null,
		private readonly ?\OCA\BookingCheck\Db\UploadMapper $uploadMapper = null,
		private readonly ?\OCA\BookingCheck\Service\UploadService $uploadService = null,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('o.id', 'o.ref', 'o.status', 'o.buyer_name', 'o.buyer_email',
			'o.amount_cents', 'o.currency', 'o.created_at', 'o.is_test', 'f.title')
			->from('bc_order', 'o')
			->leftJoin('o', 'bc_offering', 'f', $qb->expr()->eq('f.id', 'o.offering_id'))
			->orderBy('o.id', 'DESC')
			->setMaxResults(500);
		return new TemplateResponse($this->appName, 'admin/orders', [
			'navId' => 'orders',
			'orders' => $qb->executeQuery()->fetchAll(),
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(int $id): TemplateResponse
	{
		$order = $this->orderMapper->find($id);
		if ($order === null) {
			throw new NotFoundException();
		}
		$lineRows = $this->lines->findByOrder($id);
		$holdersByLine = [];
		foreach ($lineRows as $ln) {
			$names = [];
			foreach ($this->tickets->findByLine((int)$ln->getId()) as $t) {
				if ($t->getAttendeeName() !== null && $t->getAttendeeName() !== '') {
					$names[] = $t->getAttendeeName();
				}
			}
			$holdersByLine[(int)$ln->getId()] = $names;
		}
		return new TemplateResponse($this->appName, 'admin/order-detail', [
			'navId' => 'orders',
			'order' => $order->jsonSerialize(),
			'lines' => array_map(fn ($l) => $l->jsonSerialize(), $lineRows),
			'holdersByLine' => $holdersByLine,
			'payment' => $this->payments->findByOrder($id)?->jsonSerialize(),
			'invoices' => array_map(fn ($i) => $i->jsonSerialize(),
				$this->invoices->findByOrder($id)),
		]);
	}

	#[NoAdminRequired]
	public function cancel(int $id): JSONResponse
	{
		$this->mustExist($id);
		$this->orders->transitionToCancelled($id, $this->uid());
		return $this->ok();
	}

	#[NoAdminRequired]
	public function approve(int $id): JSONResponse
	{
		$this->mustExist($id);
		$this->orders->approve($id, $this->uid());
		return $this->ok();
	}

	#[NoAdminRequired]
	public function decline(int $id): JSONResponse
	{
		$this->mustExist($id);
		$this->orders->decline($id, $this->uid());
		return $this->ok();
	}

	/**
	 * Two-step refund: quote returns the policy-computed amount; the
	 * commit requires the quoted amount echoed back (admin confirms the
	 * number they saw — no blind clicks).
	 */
	#[NoAdminRequired]
	public function refundQuote(int $id): JSONResponse
	{
		$order = $this->mustExist($id);
		if ($order->getStatus() !== 'paid' && $order->getStatus() !== 'cancelled') {
			throw new ValidationException(['order' => 'Only paid orders can be refunded.']);
		}
		// The quote is what the PSP can actually move: the settled tranche
		// on the paid payment row. For a deposit order that is
		// amount−remainder, not the order total — refunding the total
		// would try to return money never collected (PSP rejects) or, if
		// accepted, hand back more than the buyer ever paid.
		$payment = $this->payments->findPaidByOrder($id);
		return $this->ok([
			'amount_cents' => $payment !== null
				? (int)$payment->getAmountCents()
				: (int)$order->getAmountCents(),
			'currency' => $order->getCurrency(),
		]);
	}

	#[NoAdminRequired]
	public function refund(int $id): JSONResponse
	{
		$order = $this->mustExist($id);
		if ($order->getStatus() !== 'paid' && $order->getStatus() !== 'cancelled') {
			throw new ValidationException(['order' => 'Only paid orders can be refunded.']);
		}
		$payment = $this->payments->findPaidByOrder($id);
		if ($payment === null || $payment->getPspRef() === null) {
			throw new ValidationException(['order' => 'No payment to refund.']);
		}
		$amount = $this->strictId($this->request->getParam('amount_cents'));
		// v1 is full-refund only — a partial amount would still release ALL
		// seats and revoke ALL tickets (the transition is terminal). The
		// quote must be echoed back exactly; partials arrive with the
		// refund-policy engine, never as a silent semantic mismatch.
		// "Full" means the settled tranche on this payment row — on a
		// deposit order the PSP only captured amount−remainder.
		if ($amount === null || $amount !== (int)$payment->getAmountCents()) {
			throw new ValidationException(['amount_cents' => 'Only full refunds are supported — echo the quoted amount.']);
		}
		$refundRef = (string)$payment->getRefundRef();
		if ($refundRef === '' || str_starts_with($refundRef, 'pending:')) {
			// CAS-claim the refund before touching the PSP — without this
			// two concurrent refund calls both pass the refund_ref check
			// and submit two real refunds. 'pending:' markers are
			// reclaimable once stale (claimant died mid-call).
			$claim = 'pending:' . bin2hex(random_bytes(8));
			if (!$this->payments->claimRefund((int)$payment->getId(), $claim)) {
				// lost the claim — a real refund_ref means already done;
				// a fresh pending marker means in flight → 409, not a
				// second PSP call and not a silent merge
				$current = $this->payments->find((int)$payment->getId());
				$currentRef = (string)$current?->getRefundRef();
				if (str_starts_with($currentRef, 'pending:')) {
					throw new StateConflictException(
						'A refund is already in progress for this order — retry in a moment.');
				}
				$refundRef = $currentRef;
			} else {
				// PSP refund first — order state only moves after money
				// moved. The real refund_ref is persisted BEFORE the
				// transition: if the transition throws, a retry finds it
				// set and skips the PSP call instead of double-refunding.
				$result = $this->psp->byName($payment->getProvider())
					->refund($payment->getPspRef(), $amount, $order->getCurrency());
				if (!$result->ok) {
					// release our claim — the PSP never moved money, so
					// a retry must be able to claim again
					$this->payments->releaseRefundClaim((int)$payment->getId(), $claim);
					return $this->fail(502, 'PAYMENT_UNAVAILABLE', 'Refund failed at the payment provider: ' . $result->error);
				}
				$refundRef = $result->refundRef !== '' ? $result->refundRef : 'ref-' . $payment->getPspRef();
				$payment->setRefundRef($refundRef);
				$this->payments->update($payment);
			}
		}
		// the credit note mints inside the refund transaction — allocate()'s
		// FOR UPDATE only serializes while a transaction is open; minting in
		// autocommit here could duplicate invoice numbers under a race.
		// GoBD: the Storno must negate the FULL invoiced document — that is
		// the order total, not the refunded tranche (a deposit order's open
		// remainder is written off by the reversal, never collected).
		$this->orders->transitionToRefunded($id, $this->uid(), ['amount' => $amount], (int)$order->getAmountCents());
		return $this->ok(['refunded_cents' => $amount, 'refund_ref' => $refundRef]);
	}

	#[NoAdminRequired]
	public function resend(int $id): JSONResponse
	{
		$order = $this->mustExist($id);
		// A "confirmation" only makes sense while the order is alive —
		// resending on a cancelled/refunded/expired order mails a working
		// manage link and a confirmation the state machine contradicts.
		if (!in_array($order->getStatus(), ['pending_payment', 'pending_approval', 'approved', 'paid'], true)) {
			throw new ValidationException(['order' => 'Only live orders can be re-sent.']);
		}
		// Re-queue the confirmation. The stored token is a hash, so a resend
		// mints a FRESH manage link and rotates the hash — the new email is
		// the working link, the old one dies (same contract as /pub/m/recover).
		[$tok, $hash] = $this->tokenService->mint();
		$this->orderMapper->rotateManageToken($id, $hash);
		$manageUrl = $this->urlGenerator
			->linkToRouteAbsolute('bookingcheck.manage.show', ['token' => $tok]);
		$this->emailService
			->queue('order_confirmed', $order->getBuyerEmail(), $order->getBuyerLocale(),
				['order_ref' => $order->getRef(), 'manage_url' => $manageUrl]);
		return $this->ok(['queued' => true]);
	}

	/**
	 * NH-19 — seller marks the open receivable (invoice-pay or deposit
	 * remainder) as received. CAS-locked inside the service.
	 */
	#[NoAdminRequired]
	public function markPaid(int $id): JSONResponse
	{
		$this->mustExist($id);
		$raw = $this->request->getParam('amount_cents');
		$cents = ($raw !== null && $raw !== '' && preg_match('/^\d+$/', (string)$raw))
			? (int)$raw : null;
		try {
			$this->orders->markRemainderReceived($id, $this->uid(), $cents);
		} catch (\OCA\BookingCheck\Exception\StateConflictException $e) {
			return $this->fail(409, $e->getCodeName(), $e->getMessage());
		}
		return $this->ok(['received' => true]);
	}

	/** NH-31 — open abuse reports queue. */
	#[NoAdminRequired]
	public function abuseList(): JSONResponse
	{
		if ($this->abuseReports === null) {
			return $this->notFound();
		}
		return $this->ok(['reports' => array_map(
			fn ($r) => $r->jsonSerialize(), $this->abuseReports->listOpen())]);
	}

	/** NH-31 — resolve a report (action: keep|purge). */
	#[NoAdminRequired]
	public function resolveAbuse(int $id): JSONResponse
	{
		if ($this->abuseReports === null) {
			return $this->notFound();
		}
		$r = $this->abuseReports->find($id);
		if ($r === null) {
			return $this->notFound();
		}
		$action = (string)$this->request->getParam('action', 'keep');
		if ($action === 'purge' && $r->getUploadId() !== null
			&& $this->uploadMapper !== null && $this->uploadService !== null) {
			$u = $this->uploadMapper->find((int)$r->getUploadId());
			if ($u !== null) {
				$this->uploadService->purge($u);
			}
		}
		// schema vocab is open|resolved|dismissed: purging the upload
		// resolves the report; keeping it dismisses the accusation
		$r->setStatus($action === 'purge' ? 'resolved' : 'dismissed');
		$r->setResolvedAt(new \DateTimeImmutable());
		$r->setResolvedBy($this->uid());
		$this->abuseReports->update($r);
		$this->auditService
			->log(null, null, $this->uid(), 'abuse.resolved', null, null,
				['report' => $id, 'action' => $action]);
		return $this->ok(['resolved' => true]);
	}

	#[NoAdminRequired]
	public function addNote(int $id): JSONResponse
	{
		$order = $this->mustExist($id);
		$note = trim((string)$this->request->getParam('note', ''));
		if (mb_strlen($note) > 2000) {
			throw new ValidationException(['note' => 'Note is limited to 2000 characters.']);
		}
		$order->setStaffNote($note === '' ? null : $note);
		$this->orderMapper->update($order);
		$this->auditService
			->log($id, (int)$order->getOfferingId(), $this->uid(), 'order.note', null, null,
				['note_len' => mb_strlen($note)]); // content stays out of audit PII
		return $this->ok(['note' => $note]);
	}

	private function mustExist(int $id): \OCA\BookingCheck\Db\Order
	{
		$order = $this->orderMapper->find($id);
		if ($order === null) {
			throw new NotFoundException();
		}
		return $order;
	}

	private function uid(): string
	{
		return $this->userSession->getUser()?->getUID() ?? 'system';
	}
}

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
use OCA\BookingCheck\Exception\PspException;
use OCA\BookingCheck\Exception\StateConflictException;
use OCA\BookingCheck\Exception\ValidationException;
use OCA\BookingCheck\Service\OrderService;
use OCA\BookingCheck\Service\RefundService;
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
		private readonly RefundService $refunds,
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
		$status = trim((string)$this->request->getParam('status', ''));
		if (!array_key_exists($status, \OCA\BookingCheck\Support\StatusLabels::STATUSES)) {
			$status = '';
		}
		$q = trim((string)$this->request->getParam('q', ''));
		$qb = $this->db->getQueryBuilder();
		$qb->select('o.id', 'o.ref', 'o.status', 'o.buyer_name', 'o.buyer_email',
			'o.amount_cents', 'o.currency', 'o.created_at', 'o.is_test', 'f.title')
			->from('bc_order', 'o')
			->leftJoin('o', 'bc_offering', 'f', $qb->expr()->eq('f.id', 'o.offering_id'))
			->orderBy('o.id', 'DESC')
			->setMaxResults(500);
		if ($status !== '') {
			$qb->andWhere($qb->expr()->eq('o.status', $qb->createNamedParameter($status)));
		}
		if ($q !== '') {
			// Support lookup: ref, buyer name, or buyer email (spec §support).
			$like = '%' . $this->db->escapeLikeParameter($q) . '%';
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->iLike('o.ref', $qb->createNamedParameter($like)),
				$qb->expr()->iLike('o.buyer_name', $qb->createNamedParameter($like)),
				$qb->expr()->iLike('o.buyer_email', $qb->createNamedParameter($like)),
			));
		}
		$rows = $qb->executeQuery()->fetchAll();
		$countQb = $this->db->getQueryBuilder();
		$countQb->select($countQb->func()->count('o.id'))->from('bc_order', 'o');
		if ($status !== '') {
			$countQb->andWhere($countQb->expr()->eq('o.status', $countQb->createNamedParameter($status)));
		}
		if ($q !== '') {
			$like = '%' . $this->db->escapeLikeParameter($q) . '%';
			$countQb->andWhere($countQb->expr()->orX(
				$countQb->expr()->iLike('o.ref', $countQb->createNamedParameter($like)),
				$countQb->expr()->iLike('o.buyer_name', $countQb->createNamedParameter($like)),
				$countQb->expr()->iLike('o.buyer_email', $countQb->createNamedParameter($like)),
			));
		}
		$total = (int)$countQb->executeQuery()->fetchOne();
		return new TemplateResponse($this->appName, 'admin/orders', [
			'navId' => 'orders',
			'orders' => $rows,
			'filterStatus' => $status,
			'filterQ' => $q,
			'total' => $total,
			'truncated' => count($rows) >= 500 && $total > count($rows),
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
	 * number they saw — no blind clicks). The executor is shared with
	 * the companion manager API — RefundService owns the money path.
	 */
	#[NoAdminRequired]
	public function refundQuote(int $id): JSONResponse
	{
		return $this->ok($this->refunds->quote($this->mustExist($id)));
	}

	#[NoAdminRequired]
	public function refund(int $id): JSONResponse
	{
		try {
			return $this->ok($this->refunds->refund(
				$this->mustExist($id),
				$this->strictId($this->request->getParam('amount_cents')),
				$this->uid()));
		} catch (PspException $e) {
			return $this->fail(502, 'PAYMENT_UNAVAILABLE', 'Payment is temporarily unavailable — please try again.');
		}
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

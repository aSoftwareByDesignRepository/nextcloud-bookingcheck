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
		if ($order->getStatus() !== 'paid') {
			throw new ValidationException(['order' => 'Only paid orders can be refunded.']);
		}
		// policy: full amount in v1 (partial refunds come with policy engine)
		return $this->ok([
			'amount_cents' => (int)$order->getAmountCents(),
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
		$amount = $this->strictId($this->request->getParam('amount_cents'));
		// v1 is full-refund only — a partial amount would still release ALL
		// seats and revoke ALL tickets (the transition is terminal). The
		// quote must be echoed back exactly; partials arrive with the
		// refund-policy engine, never as a silent semantic mismatch.
		if ($amount === null || $amount !== (int)$order->getAmountCents()) {
			throw new ValidationException(['amount_cents' => 'Only full refunds are supported — echo the quoted amount.']);
		}
		$payment = $this->payments->findPaidByOrder($id);
		if ($payment === null || $payment->getPspRef() === null) {
			throw new ValidationException(['order' => 'No payment to refund.']);
		}
		$refundRef = $payment->getRefundRef();
		if ($refundRef === null || $refundRef === '') {
			// PSP refund first — order state only moves after money moved.
			// The refund_ref is persisted BEFORE the transition: if the
			// transition below throws, a retry finds it set and skips the
			// PSP call instead of submitting a second real refund.
			$result = $this->psp->byName($payment->getProvider())
				->refund($payment->getPspRef(), $amount, $order->getCurrency());
			if (!$result->ok) {
				return $this->fail(502, 'PAYMENT_UNAVAILABLE', 'Refund failed at the payment provider: ' . $result->error);
			}
			$refundRef = $result->refundRef !== '' ? $result->refundRef : 'ref-' . $payment->getPspRef();
			$payment->setRefundRef($refundRef);
			$this->payments->update($payment);
		}
		// the credit note mints inside the refund transaction — allocate()'s
		// FOR UPDATE only serializes while a transaction is open; minting in
		// autocommit here could duplicate invoice numbers under a race.
		$this->orders->transitionToRefunded($id, $this->uid(), ['amount' => $amount], $amount);
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
		[$tok, $hash] = \OCP\Server::get(\OCA\BookingCheck\Service\TokenService::class)->mint();
		$this->orderMapper->rotateManageToken($id, $hash);
		$manageUrl = \OCP\Server::get(\OCP\IURLGenerator::class)
			->linkToRouteAbsolute('bookingcheck.manage.show', ['token' => $tok]);
		\OCP\Server::get(\OCA\BookingCheck\Service\EmailService::class)
			->queue('order_confirmed', $order->getBuyerEmail(), $order->getBuyerLocale(),
				['order_ref' => $order->getRef(), 'manage_url' => $manageUrl]);
		return $this->ok(['queued' => true]);
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
		\OCP\Server::get(\OCA\BookingCheck\Service\AuditService::class)
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

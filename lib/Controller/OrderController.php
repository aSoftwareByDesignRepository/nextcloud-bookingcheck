<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\OrderMapper;
use OCA\BookingCheck\Db\OrderLineMapper;
use OCA\BookingCheck\Db\PaymentMapper;
use OCA\BookingCheck\Exception\NotFoundException;
use OCA\BookingCheck\Exception\ValidationException;
use OCA\BookingCheck\Psp\PspManager;
use OCA\BookingCheck\Service\InvoiceService;
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
		private readonly InvoiceService $invoices,
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
		return new TemplateResponse($this->appName, 'admin/order-detail', [
			'navId' => 'orders',
			'order' => $order->jsonSerialize(),
			'lines' => array_map(fn ($l) => $l->jsonSerialize(), $this->lines->findByOrder($id)),
			'payment' => $this->payments->findByOrder($id)?->jsonSerialize(),
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
		if ($amount === null || $amount <= 0 || $amount > (int)$order->getAmountCents()) {
			throw new ValidationException(['amount_cents' => 'Invalid refund amount.']);
		}
		$payment = $this->payments->findByOrder($id);
		if ($payment === null || $payment->getPspRef() === null) {
			throw new ValidationException(['order' => 'No payment to refund.']);
		}
		// PSP refund first — order state only moves after money moved
		$result = $this->psp->byName($payment->getProvider())
			->refund($payment->getPspRef(), $amount, $order->getCurrency());
		if (!$result->ok) {
			return $this->fail(502, 'PAYMENT_UNAVAILABLE', 'Refund failed at the payment provider: ' . $result->error);
		}
		$this->invoices->issueCredit($order, $amount);
		$this->orders->transitionToRefunded($id, $this->uid(), ['amount' => $amount]);
		return $this->ok(['refunded_cents' => $amount, 'refund_ref' => $result->refundRef]);
	}

	#[NoAdminRequired]
	public function resend(int $id): JSONResponse
	{
		$this->mustExist($id);
		// re-queue the confirmation email — delivery is job-driven
		$order = $this->orderMapper->find($id);
		\OCP\Server::get(\OCA\BookingCheck\Service\EmailService::class)
			->queue('order_confirmed', $order->getBuyerEmail(), $order->getBuyerLocale(),
				['order_ref' => $order->getRef()]);
		return $this->ok(['queued' => true]);
	}

	#[NoAdminRequired]
	public function addNote(int $id): JSONResponse
	{
		$order = $this->mustExist($id);
		$note = trim((string)$this->request->getParam('note', ''));
		if ($note === '' || mb_strlen($note) > 2000) {
			throw new ValidationException(['note' => 'Note is required.']);
		}
		\OCP\Server::get(\OCA\BookingCheck\Service\AuditService::class)
			->log($id, (int)$order->getOfferingId(), $this->uid(), 'order.note', null, null,
				['note_len' => mb_strlen($note)]); // content stays out of audit PII
		return $this->ok();
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

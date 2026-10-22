<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\AnswerMapper;
use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Db\OrderLineMapper;
use OCA\BookingCheck\Db\OrderMapper;
use OCA\BookingCheck\Db\QuestionMapper;
use OCA\BookingCheck\Db\ReviewMapper;
use OCA\BookingCheck\Db\TicketMapper;
use OCA\BookingCheck\Exception\NotFoundException;
use OCA\BookingCheck\Exception\ValidationException;
use OCA\BookingCheck\Service\EmailService;
use OCA\BookingCheck\Service\OrderService;
use OCA\BookingCheck\Service\TokenService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IURLGenerator;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IRequest;

/**
 * Bearer-token manage page (§9.4).
 *
 * The token IS the authorization: 256-bit, hashed at rest, uniform 404
 * on any miss. Only the legal actions for the current state render.
 */
class ManageController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly OrderMapper $orders,
		private readonly OrderLineMapper $lines,
		private readonly TicketMapper $tickets,
		private readonly OfferingMapper $offerings,
		private readonly ReviewMapper $reviews,
		private readonly QuestionMapper $questions,
		private readonly AnswerMapper $answers,
		private readonly \OCA\BookingCheck\Service\TicketService $ticketService,
		private readonly \OCA\BookingCheck\Service\PaySessionService $paySessions,
		private readonly OrderService $orderService,
		private readonly TokenService $tokens,
		private readonly EmailService $email,
		private readonly IURLGenerator $url,
		private readonly \OCA\BookingCheck\Service\LicenseService $license,
		private readonly \OCA\BookingCheck\Service\UploadService $uploadService,
		private readonly \OCA\BookingCheck\Db\UploadMapper $uploads,
		private readonly \OCA\BookingCheck\Db\InvoiceMapper $invoices,
		private readonly \OCA\BookingCheck\Service\SettingsService $settings,
		private readonly \OCA\BookingCheck\Service\WalletPassService $wallet,
		private readonly \OCA\BookingCheck\Service\IcsService $icsService,
		private readonly \OCA\BookingCheck\Service\EInvoiceService $einvoice,
		private readonly \OCA\BookingCheck\Db\AbuseReportMapper $abuseReports,
		private readonly \OCA\BookingCheck\Service\PushService $push,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(string $token): PublicTemplateResponse
	{
		$order = $this->resolve($token);
		if ($order === null) {
			return \OCA\BookingCheck\Support\PublicNotFound::render([
				'recover' => true, // oracle-safe "send me a new link" affordance
			]);
		}
		$lineRows = array_map(fn ($l) => $l->jsonSerialize(), $this->lines->findByOrder((int)$order->getId()));
		$walletLive = $order->getStatus() === 'paid'
			&& ($this->wallet->googleAvailable() || $this->wallet->appleAvailable());
		$ticketsByLine = [];
		foreach ($lineRows as $ln) {
			foreach ($this->tickets->findByLine((int)$ln['id']) as $t) {
				$row = [
					'id' => (int)$t->getId(),
					'seat_no' => $t->getSeatNo() !== null ? (int)$t->getSeatNo() : null,
					'attendee_name' => $t->getAttendeeName(),
					'checked_in' => $t->getCheckedInAt() !== null,
				];
				if ($walletLive && $t->getRevokedAt() === null) {
					$qr = $this->ticketService->qrPayload($t);
					if ($qr !== null) {
						$title = (string)($ln['catName'] ?? 'Ticket');
						$row['wallet_google'] = $this->wallet->googleSaveUrl($order, $t, $qr, $title);
						$row['wallet_apple'] = $this->wallet->appleAvailable();
					}
				}
				$ticketsByLine[(int)$ln['id']][] = $row;
			}
		}
		// reviews are a paid-order perk on review-enabled offerings (Pro)
		$offering = $this->offerings->find((int)$order->getOfferingId());
		$reviewEnabled = $order->getStatus() === 'paid'
			&& (int)($offering?->getReviewDisplay() ?? 0) === 1
			&& $this->license->hasFeature('reviews');
		// per-attendee intake: active questions on a paid order get a form (Pro)
		$questionRows = $order->getStatus() === 'paid' && $offering !== null
			&& $this->license->hasFeature('intake_questions')
			? $this->questions->findActiveByOffering((int)$order->getOfferingId()) : [];
		$allTicketIds = [];
		foreach ($ticketsByLine as $ts) {
			foreach ($ts as $t) {
				$allTicketIds[] = $t['id'];
			}
		}
		return new PublicTemplateResponse($this->appName, 'public/manage', [
			'order' => [
				'ref' => $order->getRef(), 'status' => $order->getStatus(),
				'amount_cents' => (int)$order->getAmountCents(), 'currency' => $order->getCurrency(),
				'buyer_name' => $order->getBuyerName(),
			],
			'lines' => $lineRows,
			'token' => $token,
			'ticketsByLine' => $ticketsByLine,
			'actions' => $this->legalActions($order->getStatus()),
			'reviewEnabled' => $reviewEnabled,
			'reviewed' => $reviewEnabled
				? $this->reviews->reviewedLineIds(array_map(fn ($l) => (int)$l['id'], $lineRows))
				: [],
			'questions' => array_map(fn ($q) => [
				'id' => (int)$q->getId(), 'label' => $q->getLabel(), 'kind' => $q->getKind(),
				'options' => $q->getOptionsJson() !== null ? (json_decode((string)$q->getOptionsJson(), true) ?: []) : [],
				'required' => (int)$q->getRequired() === 1,
			], $questionRows),
			'answers' => $questionRows !== [] ? $this->answers->answersByTicket($allTicketIds) : [],
			'canUpload' => $offering !== null && $order->getStatus() === 'paid'
				&& $this->uploadService->enabledFor($offering),
			'uploads' => $offering !== null ? $this->uploadService->listFor($order, $offering) : [],
		]);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function cancel(string $token): JSONResponse
	{
		$order = $this->resolve($token);
		if ($order === null) {
			throw new NotFoundException();
		}
		if ($order->getStatus() === 'paid') {
			$this->orderService->transitionToCancelled((int)$order->getId(), 'buyer');
		} else {
			$this->orderService->cancelPending((int)$order->getId(), 'buyer');
		}
		return $this->ok(['status' => 'cancelled']);
	}

	/** ICS download for the booking — RFC5545-escaped at the encoder. */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function ics(string $token): DataDownloadResponse
	{
		$order = $this->resolve($token);
		if ($order === null) {
			throw new NotFoundException();
		}
		$ics = $this->icsService->forOrder($order);
		$resp = new DataDownloadResponse($ics, 'booking-' . $order->getRef() . '.ics', 'text/calendar');
		return $resp;
	}

	/** NH-17 — EN16931 e-invoice (CII XML) for the buyer. */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function einvoice(string $token): \OCP\AppFramework\Http\DataDownloadResponse
	{
		$order = $this->resolve($token);
		if ($order === null) {
			throw new NotFoundException();
		}
		$svc = $this->einvoice;
		if (!$svc->isAvailable()) {
			throw new NotFoundException();
		}
		$invoice = $this->invoices
			->findByOrderAndType((int)$order->getId(), 'invoice');
		if ($invoice === null) {
			throw new NotFoundException();
		}
		return new \OCP\AppFramework\Http\DataDownloadResponse(
			$svc->ciiXml($invoice, $order),
			'einvoice-' . $order->getRef() . '.xml', 'application/xml');
	}

	/**
	 * NH-30 wallet adapter — Apple .pkpass download. Same manage-token
	 * entitlement as the ticket page; 404s identically when the seller
	 * never configured Apple creds (feature stays invisible).
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function appleWallet(string $token, int $ticketId): \OCP\AppFramework\Http\DataDownloadResponse
	{
		$order = $this->resolve($token);
		if ($order === null || $order->getStatus() !== 'paid') {
			throw new NotFoundException();
		}
		$ticket = $this->tickets->find($ticketId);
		if ($ticket === null || $ticket->getRevokedAt() !== null) {
			throw new NotFoundException();
		}
		$line = $this->lines->find((int)$ticket->getLineId());
		if ($line === null || (int)$line->getOrderId() !== (int)$order->getId()) {
			throw new NotFoundException();
		}
		$qr = $this->ticketService->qrPayload($ticket);
		if ($qr === null) {
			throw new NotFoundException();
		}
		$bytes = $this->wallet->applePass($order, $ticket, $qr, (string)($line->getCatName() ?? 'Ticket'));
		if ($bytes === null) {
			throw new NotFoundException();
		}
		return new \OCP\AppFramework\Http\DataDownloadResponse(
			$bytes, 'ticket-' . $ticketId . '.pkpass', 'application/vnd.apple.pkpass');
	}

	/**
	 * Printable invoice — the frozen GoBD document for a paid order.
	 * Test orders never allocate numbers, so they have nothing to print.
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function invoice(string $token): PublicTemplateResponse
	{
		$order = $this->resolve($token);
		$invoice = $order !== null
			? $this->invoices->findByOrderAndType((int)$order->getId(), 'invoice')
			: null;
		if ($order === null || $invoice === null) {
			return \OCA\BookingCheck\Support\PublicNotFound::render([]);
		}
		$offering = $this->offerings->find((int)$order->getOfferingId());
		return new PublicTemplateResponse($this->appName, 'public/invoice', [
			'token' => $token,
			// whitelist — the full serialize carries staffNote/consentText/
			// promo internals that have no business on a buyer invoice
			'order' => [
				'ref' => $order->getRef(),
				'status' => $order->getStatus(),
				'buyerName' => $order->getBuyerName(),
				'buyerCompany' => $order->getBuyerCompany(),
				'buyerEmail' => $order->getBuyerEmail(),
				'billingCountry' => $order->getBillingCountry(),
				'vatId' => $order->getVatId(),
			],
			'offeringTitle' => $offering?->getTitle() ?? '',
			'invoice' => $invoice->jsonSerialize(),
			'credits' => array_map(fn ($i) => $i->jsonSerialize(),
				array_filter($this->invoices->findByOrder((int)$order->getId()),
					fn ($i) => $i->getType() === 'credit')),
			'lines' => array_map(fn ($l) => $l->jsonSerialize(),
				$this->lines->findByOrder((int)$order->getId())),
			'seller' => [
				'name' => $this->settings->get('seller_name'),
				'email' => $this->settings->get('seller_email'),
				'address' => $this->settings->get('seller_address'),
				'vatId' => $this->settings->get('seller_vat_id'),
				'country' => $this->settings->get('seller_country'),
				'kleinunternehmer' => $this->settings->getBool('kleinunternehmer'),
			],
			// the printed rate is an order FACT — the offering's mutable
			// rate would relabel a frozen invoice after a seller edit
			'vatBp' => (int)$order->getVatBp() >= 0
				? (int)$order->getVatBp()
				: (int)($offering?->getVatBp() ?? 0),
		]);
	}

	/** One ticket page — QR SVG for the door, scoped to this order. */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function ticket(string $token, int $ticketId): PublicTemplateResponse
	{
		$order = $this->resolve($token);
		if ($order === null) {
			throw new NotFoundException();
		}
		$ticket = $this->tickets->find($ticketId);
		if ($ticket === null) {
			throw new NotFoundException();
		}
		// scope: the ticket's line must belong to THIS order — else uniform 404
		$line = $this->lines->find((int)$ticket->getLineId());
		if ($line === null || (int)$line->getOrderId() !== (int)$order->getId()) {
			throw new NotFoundException();
		}
		$revoked = $ticket->getRevokedAt() !== null;
		return new PublicTemplateResponse($this->appName, 'public/ticket', [
			'ticket' => [
				'id' => (int)$ticket->getId(),
				'name' => $ticket->getAttendeeName(),
				'seat_no' => $ticket->getSeatNo(),
				'resource' => $ticket->getResourceName(),
				'checked_in' => $ticket->getCheckedInAt() !== null,
				'revoked' => $revoked,
			],
			'order' => ['ref' => $order->getRef()],
			// a revoked ticket shows no QR — a scannable code would imply validity
			'qrSvg' => $revoked ? null : $this->ticketService->qrSvg($ticket),
			'token' => $token,
		]);
	}

	/**
	 * Per-attendee intake answers — POST /pub/m/{token}/answers.
	 * Payload: {ticket_id, answers: {question_id: value}}.
	 * The ticket must belong to this order; every question must belong to
	 * the order's offering; required questions must be answered.
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function answers(string $token): JSONResponse
	{
		$order = $this->resolve($token);
		if ($order === null || $order->getStatus() !== 'paid') {
			throw new NotFoundException();
		}
		$ticketId = $this->strictId($this->request->getParam('ticket_id'));
		$ticket = $ticketId !== null ? $this->tickets->find($ticketId) : null;
		$line = $ticket !== null ? $this->lines->find((int)$ticket->getLineId()) : null;
		if ($line === null || (int)$line->getOrderId() !== (int)$order->getId()) {
			throw new NotFoundException(); // foreign ticket — uniform answer
		}
		$questions = $this->questions->findActiveByOffering((int)$order->getOfferingId());
		if ($questions === []) {
			throw new NotFoundException();
		}
		$raw = $this->request->getParam('answers', []);
		$raw = is_string($raw) ? (json_decode($raw, true) ?: []) : (is_array($raw) ? $raw : []);
		$fields = [];
		$byId = [];
		foreach ($questions as $q) {
			$byId[(int)$q->getId()] = $q;
			if ((int)$q->getRequired() === 1) {
				$v = trim((string)($raw[(string)$q->getId()] ?? $raw[$q->getId()] ?? ''));
				if ($v === '') {
					$fields['q' . (int)$q->getId()] = 'This answer is required.';
				}
			}
		}
		if ($fields !== []) {
			throw new ValidationException($fields);
		}
		foreach ($raw as $qid => $value) {
			$q = $byId[(int)$qid] ?? null;
			if ($q === null) {
				continue; // unknown/foreign question ids are ignored, never stored
			}
			$v = trim(mb_substr((string)$value, 0, 2000));
			if ($q->getKind() === 'select' && $v !== '') {
				$options = json_decode((string)$q->getOptionsJson(), true) ?: [];
				if (!in_array($v, $options, true)) {
					continue; // forged option — skip rather than store garbage
				}
			}
			if ($q->getKind() === 'checkbox') {
				$v = in_array($v, ['1', 'true'], true) ? '1' : '0';
			}
			$this->answers->upsert($ticketId, (int)$q->getId(), $v, (int)$q->getWordingRev());
		}
		return $this->ok(['saved' => true]);
	}

	/**
	 * Attendee upload — POST /pub/m/{token}/uploads (multipart `file` +
	 * `display_name` + `line_id` of one of this order's seat lines).
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function uploadFile(string $token): JSONResponse
	{
		$order = $this->resolve($token);
		$offering = $order !== null ? $this->offerings->find((int)$order->getOfferingId()) : null;
		if ($order === null || $offering === null) {
			throw new NotFoundException();
		}
		$lineId = $this->strictId($this->request->getParam('line_id'));
		$displayName = trim((string)$this->request->getParam('display_name', ''));
		$file = $this->request->getUploadedFile('file');
		if ($lineId === null || $file === null) {
			throw new ValidationException(['file' => 'Choose a file to share.']);
		}
		$u = $this->uploadService->store($order, $offering, $lineId, $displayName, $file);
		$row = $u->jsonSerialize();
		$row['own'] = true;
		return $this->ok(['upload' => $row], 201);
	}

	/** Download a visible upload — GET /pub/m/{token}/uploads/{id}. */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function uploadDownload(string $token, int $id): \OCP\AppFramework\Http\StreamResponse
	{
		$order = $this->resolve($token);
		$u = $order !== null ? $this->uploads->find($id) : null;
		if ($order === null || $u === null
			|| (int)$u->getOfferingId() !== (int)$order->getOfferingId()) {
			throw new NotFoundException();
		}
		$offering = $this->offerings->find((int)$u->getOfferingId());
		return $this->uploadService->streamFor($u, $order, $offering);
	}

	/** Remove own upload — DELETE /pub/m/{token}/uploads/{id}. */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function uploadDelete(string $token, int $id): JSONResponse
	{
		$order = $this->resolve($token);
		$u = $order !== null ? $this->uploads->find($id) : null;
		if ($order === null || $u === null || $u->getDeletedAt() !== null
			|| (int)$u->getOfferingId() !== (int)$order->getOfferingId()) {
			throw new NotFoundException();
		}
		$this->uploadService->delete($u, $order);
		return $this->ok(['deleted' => true]);
	}

	/** NH-15 — thumbnail for a shared upload (same entitlement funnel). */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function uploadThumb(string $token, int $id): \OCP\AppFramework\Http\StreamResponse
	{
		$order = $this->resolve($token);
		$u = $order !== null ? $this->uploads->find($id) : null;
		if ($order === null || $u === null
			|| (int)$u->getOfferingId() !== (int)$order->getOfferingId()) {
			throw new NotFoundException();
		}
		$offering = $this->offerings->find((int)$u->getOfferingId());
		return $this->uploadService->thumbFor($u, $order, $offering);
	}

	/**
	 * NH-31 — report a shared upload as abusive. The reporter is the
	 * buyer behind this manage link; dedupe per reporter+upload keeps
	 * the queue clean, foreign upload ids collapse to 404.
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function reportAbuse(string $token, int $id): JSONResponse
	{
		$order = $this->resolve($token);
		$u = $order !== null ? $this->uploads->find($id) : null;
		if ($order === null || $u === null
			|| (int)$u->getOfferingId() !== (int)$order->getOfferingId()) {
			throw new NotFoundException();
		}
		$offering = $this->offerings->find((int)$u->getOfferingId());
		// same viewability gate as the download route — invisible ⇒ 404
		$this->uploadService->assertViewable($u, $order, $offering);
		$reason = trim((string)$this->request->getParam('reason', ''));
		if (mb_strlen($reason) > 500) {
			return $this->fail(400, 'VALIDATION', 'Reason is limited to 500 characters.');
		}
		$mapper = $this->abuseReports;
		$reporter = strtolower((string)$order->getBuyerEmail());
		foreach ($mapper->listForUpload((int)$u->getId()) as $r) {
			if ($r->getReporter() === $reporter && $r->getStatus() === 'open') {
				return $this->ok(['reported' => true, 'duplicate' => true]);
			}
		}
		$r = new \OCA\BookingCheck\Db\AbuseReport();
		$r->setUploadId((int)$u->getId());
		$r->setReporter($reporter);
		$r->setReason($reason === '' ? 'no reason given' : $reason);
		$r->setStatus('open');
		$r->setCreatedAt(new \DateTimeImmutable());
		try {
			$mapper->insert($r);
		} catch (\OCP\DB\Exception $e) {
			if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			return $this->ok(['reported' => true, 'duplicate' => true]);
		}
		$this->push
			->notifyManagers('abuse.reported', []);
		return $this->ok(['reported' => true], 201);
	}

	/** "Pay now" for approved/ttl-extended orders — fresh PSP session. */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function pay(string $token): RedirectResponse
	{
		$order = $this->resolve($token);
		if ($order === null) {
			throw new NotFoundException();
		}
		$back = $this->url->linkToRouteAbsolute('bookingcheck.manage.show', ['token' => $token]);
		if (!in_array($order->getStatus(), ['pending_payment', 'approved'], true)) {
			return new RedirectResponse($back);
		}
		try {
			$session = $this->paySessions->createForOrder($order);
		} catch (\OCA\BookingCheck\Exception\PspException) {
			return new RedirectResponse($back);
		}
		return new RedirectResponse($session->checkoutUrl);
	}

	/**
	 * Lost-link recovery — uniform 202, sends only when the email matches
	 * a live order (per-email send cap inside EmailService).
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function recover(): JSONResponse
	{
		$email = strtolower(trim((string)$this->request->getParam('email', '')));
		if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
			// mint a fresh manage link for the newest live order of that
			// email — a new token, never the old hash's plaintext
			$order = $this->orders->findLatestLiveForEmail($email);
			if ($order !== null) {
				[$token, $hash] = $this->tokens->mint();
				$this->orders->rotateManageToken((int)$order->getId(), $hash);
				$this->email->queue('manage_link', $email, $order->getBuyerLocale(), [
					'order_ref' => $order->getRef(),
					'manage_url' => $this->url->linkToRouteAbsolute(
						'bookingcheck.manage.show', ['token' => $token]),
				], capped: true);
			}
		}
		return $this->ok(['sent' => true], 202); // uniform — no oracle
	}

	private function resolve(string $token): ?\OCA\BookingCheck\Db\Order
	{
		if ($token === '' || strlen($token) > 128) {
			return null;
		}
		$order = $this->orders->findByManageTokenHash($this->tokens->hash($token));
		if ($order === null) {
			return null;
		}
		// TTL: manage link dies 90d after the last booked occurrence ends
		$last = $this->lines->lastBookedEnd((int)$order->getId());
		if ($last !== null && $last < new \DateTimeImmutable('-90 days')) {
			return null;
		}
		return $order;
	}

	private function legalActions(string $status): array
	{
		return match ($status) {
			'paid' => ['cancel', 'ics', 'tickets', 'invoice'],
			'pending_payment', 'approved' => ['cancel', 'pay'],
			'pending_approval' => ['cancel'],
			// refunded buyers still need the invoice + credit note for their books
			'refunded' => ['invoice'],
			default => [],
		};
	}
}

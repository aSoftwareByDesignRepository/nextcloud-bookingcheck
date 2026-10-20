<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Db\OrderMapper;
use OCA\BookingCheck\Db\TicketMapper;
use OCA\BookingCheck\Service\EmailService;
use OCA\BookingCheck\Service\EntitlementService;
use OCA\BookingCheck\Service\OtpService;
use OCA\BookingCheck\Service\PortalSessionService;
use OCA\BookingCheck\Service\SettingsService;
use OCA\BookingCheck\Service\TicketService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Email-OTP customer portal (§9.8).
 *
 * Uniform answers everywhere: send → identical 202; verify → identical
 * failure copy for wrong/expired/unknown. Entitlement is evaluated per
 * request by EntitlementService — a session never snapshots access.
 */
class PortalController extends Controller
{
	use ApiControllerTrait;

	public const COOKIE = 'bck_portal';

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly OtpService $otp,
		private readonly PortalSessionService $sessions,
		private readonly EntitlementService $entitlement,
		private readonly EmailService $email,
		private readonly SettingsService $settings,
		private readonly IURLGenerator $urlGenerator,
		private readonly OrderMapper $orders,
		private readonly OfferingMapper $offerings,
		private readonly TicketMapper $tickets,
		private readonly TicketService $ticketService,
		private readonly ?\OCA\BookingCheck\Db\DownloadLogMapper $downloadLog = null,
		private readonly ?\OCA\BookingCheck\Db\AbuseReportMapper $abuseReports = null,
		private readonly ?\OCA\BookingCheck\Db\UploadMapper $uploads = null,
		private readonly ?\OCA\BookingCheck\Service\MaterialService $materials = null,
		private readonly ?\OCA\BookingCheck\Service\UploadService $uploadService = null,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function request(): PublicTemplateResponse
	{
		return new PublicTemplateResponse($this->appName, 'public/portal-request', []);
	}

	/**
	 * POST /pub/api/portal/send {email}
	 * Identical 202 for every input — existence oracle impossible.
	 * OTP only goes out when the email has ≥1 entitled order.
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function send(): JSONResponse
	{
		$email = strtolower(trim((string)$this->request->getParam('email', '')));
		if (filter_var($email, FILTER_VALIDATE_EMAIL)
			&& $this->entitlement->entitledOrders($email) !== []) {
			[$code, $magic] = $this->otp->issue($email, $this->request->getRemoteAddress() ?: 'unknown');
			$this->email->queue('otp', $email, $this->settings->get('default_locale'), [
				'code' => $code,
				'magic_url' => $this->urlGenerator->linkToRouteAbsolute(
					'bookingcheck.portal.magic', ['token' => $magic]),
			], capped: true); // per-email bomb cap — rotating IPs can't mailbomb
		}
		return $this->ok(['sent' => true], 202);
	}

	/**
	 * POST /pub/api/portal/verify {email, code}
	 * Single failure string for every miss — no oracle.
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function verify(): JSONResponse
	{
		$email = strtolower(trim((string)$this->request->getParam('email', '')));
		$code = preg_replace('/\D/', '', (string)$this->request->getParam('code', ''));
		if (!$this->otp->verify($email, $code)) {
			return $this->fail(401, 'INVALID_CODE', 'That code is invalid or has expired — request a new one.');
		}
		[$token] = $this->sessions->create($email, (string)$this->request->getHeader('User-Agent'));
		$resp = $this->ok(['redirect' => $this->urlGenerator->linkToRoute('bookingcheck.portal.home')]);
		$resp->addCookie(self::COOKIE, $token, new \DateTime('+30 days'), 'lax');
		return $resp;
	}

	/** Magic link — same bearer semantics as the code, one-time use. */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function magic(string $token): \OCP\AppFramework\Http\Response
	{
		$email = $this->otp->consumeMagic($token);
		if ($email === null) {
			return new PublicTemplateResponse($this->appName, 'public/not-found', []);
		}
		[$sessionToken] = $this->sessions->create($email, (string)$this->request->getHeader('User-Agent'));
		$resp = new RedirectResponse($this->urlGenerator->linkToRoute('bookingcheck.portal.home'));
		$resp->addCookie(self::COOKIE, $sessionToken, new \DateTime('+30 days'), 'lax');
		return $resp;
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function home(): PublicTemplateResponse
	{
		$email = $this->portalEmail();
		if ($email === null) {
			return new PublicTemplateResponse($this->appName, 'public/portal-request', []);
		}
		return new PublicTemplateResponse($this->appName, 'public/portal-home', [
			'orders' => $this->entitlement->entitledOrders($email),
			'materials' => $this->entitlement->entitledMaterials($email),
			'email' => $email,
		]);
	}

	/**
	 * One order in the portal — the "Open" link on the home page.
	 *
	 * Scope is least-PII: the buyer sees every live ticket; an email that
	 * is only a ticket attendee sees just their own tickets on that
	 * order — never the buyer's name, email, or the rest of the party.
	 * Unentitled and foreign order ids collapse to the same 404.
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function order(int $id): PublicTemplateResponse
	{
		$email = $this->portalEmail();
		$scope = $email === null ? null : $this->entitlement->orderScope($email, $id);
		$order = $this->orders->find($id);
		$email = $email === null ? null : strtolower($email);
		if ($email === null || $scope === null || $order === null) {
			throw new \OCA\BookingCheck\Exception\NotFoundException();
		}
		$offering = $this->offerings->find((int)$order->getOfferingId());
		$tickets = [];
		foreach ($this->tickets->findForOrder($id) as $t) {
			if ($scope === 'attendee'
				&& strtolower((string)$t->getAttendeeEmail()) !== $email) {
				continue; // a guest sees their own tickets only
			}
			$tickets[] = [
				'attendee_name' => $t->getAttendeeName(),
				'seat_no' => $t->getSeatNo(),
				'checked_in' => $t->getCheckedInAt() !== null,
				'qr_svg' => $this->ticketService->qrSvg($t),
			];
		}
		// materials for this order's offering (entitlement already proven)
		$materials = array_values(array_filter(
			$this->entitlement->entitledMaterials($email),
			fn ($m) => (int)$m['offering_id'] === (int)$order->getOfferingId()));
		return new PublicTemplateResponse($this->appName, 'public/portal-order', [
			'ref' => $order->getRef(),
			'title' => $offering?->getTitle() ?? '',
			'status' => $order->getStatus(),
			'scope' => $scope,
			'tickets' => $tickets,
			'materials' => $materials,
		]);
	}

	/** Material download — entitlement re-evaluated at download time. */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function download(int $id): \OCP\AppFramework\Http\Response
	{
		$email = $this->portalEmail();
		if ($email === null || !$this->entitlement->canAccessMaterial($email, $id)) {
			throw new \OCA\BookingCheck\Exception\NotFoundException();
		}
		$this->downloadLog?->record($id, null, null, 'portal:' . $email);
		if ($this->materials === null) {
			throw new \OCA\BookingCheck\Exception\NotFoundException();
		}
		return $this->materials->stream($id);
	}

	/** NH-14 — inline preview (PDF/images only — others fall back to download). */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function preview(int $id): \OCP\AppFramework\Http\Response
	{
		$email = $this->portalEmail();
		if ($email === null || !$this->entitlement->canAccessMaterial($email, $id)) {
			throw new \OCA\BookingCheck\Exception\NotFoundException();
		}
		$this->downloadLog?->record($id, null, null, 'portal-preview:' . $email);
		if ($this->materials === null) {
			throw new \OCA\BookingCheck\Exception\NotFoundException();
		}
		return $this->materials->streamPreview($id);
	}

	/**
	 * NH-15 — upload thumbnail in the portal. The viewer must be
	 * entitled to the upload's order (buyer or attendee scope).
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function uploadThumb(int $id): \OCP\AppFramework\Http\StreamResponse
	{
		$email = $this->portalEmail();
		$u = $email === null || $this->uploads === null ? null : $this->uploads->find($id);
		if ($email === null || $u === null) {
			throw new \OCA\BookingCheck\Exception\NotFoundException();
		}
		// visibility is evaluated from the VIEWER's order on the upload's
		// offering — the uploader's order is irrelevant here (NH-15)
		$viewerOrderId = $this->entitlement->viewerOrderIdForOffering(
			strtolower($email), (int)$u->getOfferingId());
		$order = $viewerOrderId === null ? null : $this->orders->find($viewerOrderId);
		$offering = $this->offerings->find((int)$u->getOfferingId());
		if ($order === null || $offering === null) {
			throw new \OCA\BookingCheck\Exception\NotFoundException();
		}
		if ($this->uploadService === null) {
			throw new \OCA\BookingCheck\Exception\NotFoundException();
		}
		return $this->uploadService->thumbFor($u, $order, $offering);
	}

	/**
	 * NH-31 — report an uploaded file as abusive. Session-gated,
	 * deduplicated per reporter+upload, never reveals upload existence
	 * (foreign upload ids get the same 404 as everything else).
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function reportAbuse(): JSONResponse
	{
		$email = $this->portalEmail();
		if ($email === null || $this->abuseReports === null || $this->uploads === null) {
			throw new \OCA\BookingCheck\Exception\NotFoundException();
		}
		$uploadId = $this->strictId($this->request->getParam('upload_id'));
		$reason = trim((string)$this->request->getParam('reason', ''));
		if ($uploadId === null || $reason === '' || mb_strlen($reason) > 500) {
			return $this->fail(400, 'VALIDATION', 'upload_id and a short reason are required.');
		}
		$u = $this->uploads->find($uploadId);
		// you can only report what you can see — same viewer-order +
		// sharing-scope gate as streaming (uniform 404 otherwise)
		$viewerOrderId = $u === null ? null : $this->entitlement->viewerOrderIdForOffering(
			strtolower($email), (int)$u->getOfferingId());
		$order = $viewerOrderId === null ? null : $this->orders->find($viewerOrderId);
		$offering = $u === null ? null : $this->offerings->find((int)$u->getOfferingId());
		if ($u === null || $order === null || $offering === null) {
			throw new \OCA\BookingCheck\Exception\NotFoundException();
		}
		if ($this->uploadService === null) {
			throw new \OCA\BookingCheck\Exception\NotFoundException();
		}
		$this->uploadService->assertViewable($u, $order, $offering);
		foreach ($this->abuseReports->listForUpload($uploadId) as $r) {
			if ($r->getReporter() === strtolower($email) && $r->getStatus() === 'open') {
				return $this->ok(['reported' => true, 'duplicate' => true]);
			}
		}
		$r = new \OCA\BookingCheck\Db\AbuseReport();
		$r->setUploadId($uploadId);
		$r->setReporter(strtolower($email));
		$r->setReason($reason);
		$r->setStatus('open');
		$r->setCreatedAt(new \DateTimeImmutable());
		try {
			$this->abuseReports->insert($r);
		} catch (\OCP\DB\Exception $e) {
			if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			// raced a concurrent report from the same reporter — the unique
			// index kept exactly one; treat the loser as the duplicate it is
			return $this->ok(['reported' => true, 'duplicate' => true]);
		}
		return $this->ok(['reported' => true], 201);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function logout(): JSONResponse
	{
		$token = $this->request->getCookie(self::COOKIE);
		if ($token !== null) {
			$this->sessions->revoke($token);
		}
		$resp = $this->ok(['logged_out' => true]);
		$resp->addCookie(self::COOKIE, '', new \DateTime('-1 hour'), 'lax');
		return $resp;
	}

	private function portalEmail(): ?string
	{
		$token = $this->request->getCookie(self::COOKIE);
		return $token === null ? null : $this->sessions->resolve($token);
	}
}

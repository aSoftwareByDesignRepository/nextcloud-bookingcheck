<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Service\EmailService;
use OCA\BookingCheck\Service\EntitlementService;
use OCA\BookingCheck\Service\OtpService;
use OCA\BookingCheck\Service\PortalSessionService;
use OCA\BookingCheck\Service\SettingsService;
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
		$resp->addCookie(self::COOKIE, $token,
			new \DateTime('+30 days'), true, true, 'lax');
		return $resp;
	}

	/** Magic link — same bearer semantics as the code, one-time use. */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function magic(string $token)
	{
		$email = $this->otp->consumeMagic($token);
		if ($email === null) {
			return new PublicTemplateResponse($this->appName, 'public/not-found', []);
		}
		[$sessionToken] = $this->sessions->create($email, (string)$this->request->getHeader('User-Agent'));
		$resp = new RedirectResponse($this->urlGenerator->linkToRoute('bookingcheck.portal.home'));
		$resp->addCookie(self::COOKIE, $sessionToken,
			new \DateTime('+30 days'), true, true, 'lax');
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

	/** Material download — entitlement re-evaluated at download time. */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function download(int $id)
	{
		$email = $this->portalEmail();
		if ($email === null || !$this->entitlement->canAccessMaterial($email, $id)) {
			throw new \OCA\BookingCheck\Exception\NotFoundException();
		}
		return \OCP\Server::get(\OCA\BookingCheck\Service\MaterialService::class)->stream($id);
	}

	#[PublicPage]
	#[NoAdminRequired]
	public function logout(): JSONResponse
	{
		$token = $this->request->getCookie(self::COOKIE);
		if ($token !== null) {
			$this->sessions->revoke($token);
		}
		$resp = $this->ok(['logged_out' => true]);
		$resp->addCookie(self::COOKIE, '', new \DateTime('-1 hour'), true, true, 'lax');
		return $resp;
	}

	private function portalEmail(): ?string
	{
		$token = $this->request->getCookie(self::COOKIE);
		return $token === null ? null : $this->sessions->resolve($token);
	}
}

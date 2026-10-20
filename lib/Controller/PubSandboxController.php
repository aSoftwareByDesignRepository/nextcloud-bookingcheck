<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\OrderMapper;
use OCA\BookingCheck\Db\PaymentMapper;
use OCA\BookingCheck\Exception\NotFoundException;
use OCA\BookingCheck\Psp\PspManager;
use OCA\BookingCheck\Psp\TestProvider;
use OCA\BookingCheck\Service\PspEventService;
use OCA\BookingCheck\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\IRequest;

/**
 * §9.13 sandbox checkout surface. Dead (404) unless psp_provider=test — an
 * admin opt-in via occ config, never selectable in the settings UI.
 * The settle action signs a webhook server-side and fires it through the
 * real /psp/test receiver, so payment truth still flows over the verified
 * webhook path — no bypass of the state machine.
 */
class PubSandboxController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly SettingsService $settings,
		private readonly PaymentMapper $payments,
		private readonly OrderMapper $orders,
		private readonly PspManager $psp,
		private readonly PspEventService $processor,
	) {
		parent::__construct($appName, $request);
	}

	private function guard(): void
	{
		if ($this->settings->get('psp_provider') !== 'test') {
			throw new NotFoundException();
		}
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function pay(string $pspRef): PublicTemplateResponse
	{
		$this->guard();
		$payment = $this->payments->findByPspRef('test', $pspRef);
		if ($payment === null) {
			throw new NotFoundException();
		}
		return new PublicTemplateResponse($this->appName, 'public/sandbox', [
			'pspRef' => $pspRef,
			'status' => $payment->getStatus(),
		]);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settle(string $pspRef): JSONResponse
	{
		$this->guard();
		$payment = $this->payments->findByPspRef('test', $pspRef);
		if ($payment === null) {
			throw new NotFoundException();
		}
		$outcome = (string)$this->request->getParam('outcome', 'paid');
		if (!in_array($outcome, ['paid', 'failed', 'refunded'], true)) {
			$outcome = 'paid';
		}
		$payload = json_encode([
			'id' => 'evt_' . bin2hex(random_bytes(8)),
			'psp_ref' => $pspRef,
			'status' => $outcome,
			'amount_cents' => (int)$payment->getAmountCents(),
			'currency' => $this->orders->find((int)$payment->getOrderId())?->getCurrency(),
		]);
		if ($payload === false) {
			return new JSONResponse(['error' => 'payload encode failed'], 500);
		}
		$secret = $this->settings->get('psp_test_secret');
		if ($secret === '') {
			return new JSONResponse(['error' => 'Sandbox secret not configured'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		// In-process delivery through the real verification path — the webhook
		// URL is not guaranteed reachable from inside this host (containers).
		// The signature is still minted and verified; nothing bypasses PSP truth.
		$sig = hash_hmac('sha256', $payload, $secret);
		$provider = $this->psp->byName('test');
		if (!$provider instanceof TestProvider) {
			return new JSONResponse(['error' => 'sandbox provider unavailable'], 500);
		}
		try {
			$event = $provider->verifyRaw($payload, $sig);
		} catch (\OCA\BookingCheck\Exception\PspException $e) {
			return new JSONResponse(['error' => 'sandbox self-verification failed'], 500);
		}
		$result = $this->processor->process('test', $event);
		return new JSONResponse(['ok' => $result === 'ok', 'status' => $result, 'outcome' => $outcome],
			$result === 'error' ? 500 : 200);
	}
}

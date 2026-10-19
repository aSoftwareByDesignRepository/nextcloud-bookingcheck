<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Exception\PspException;
use OCA\BookingCheck\Psp\PspManager;
use OCA\BookingCheck\Psp\VerifiedEvent;
use OCA\BookingCheck\Service\PspEventService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Inbound PSP webhooks — the ONLY path that can move an order to paid.
 *
 * Pipeline per event: authenticate (signature/refetch) → dedupe on
 * event_id (replay = instant 200, never reprocess) → apply the state
 * transition through the CAS-guarded service → mark processed.
 * A forged or replayed POST can never fabricate a payment.
 */
class PspController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PspManager $psp,
		private readonly PspEventService $processor,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function mollie(): JSONResponse
	{
		return $this->handle('mollie');
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function stripe(): JSONResponse
	{
		return $this->handle('stripe');
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function test(): JSONResponse
	{
		return $this->handle('test');
	}

	private function handle(string $providerName): JSONResponse
	{
		try {
			$event = $this->psp->byName($providerName)->verifyWebhook($this->request);
		} catch (PspException $e) {
			// unverifiable → reject; PSP will retry per its own policy
			$this->logger->notice($providerName . ' webhook rejected: ' . $e->getMessage());
			return new JSONResponse(['error' => 'unverified'], 400);
		}

		return match ($this->processor->process($providerName, $event)) {
			'duplicate' => new JSONResponse(['status' => 'duplicate']),
			'error' => new JSONResponse(['error' => 'processing'], 500),
			default => new JSONResponse(['status' => 'ok']),
		};
	}


}

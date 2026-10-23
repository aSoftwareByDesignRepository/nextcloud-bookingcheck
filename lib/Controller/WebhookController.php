<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\Webhook;
use OCA\BookingCheck\Db\WebhookMapper;
use OCA\BookingCheck\Exception\NotFoundException;
use OCA\BookingCheck\Exception\ValidationException;
use OCA\BookingCheck\Service\OutboundWebhookService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUserSession;

class WebhookController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly WebhookMapper $webhooks,
		private readonly OutboundWebhookService $service,
		private readonly IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse
	{
		return new TemplateResponse($this->appName, 'admin/webhooks', [
			'navId' => 'webhooks',
			'webhooks' => array_map(fn ($w) => [
				'id' => (int)$w->getId(), 'url' => $w->getUrl(),
				'events' => json_decode($w->getEventsJson(), true),
				'active' => (int)$w->getActive(),
			], $this->webhooks->findAll()),
		]);
	}

	#[NoAdminRequired]
	public function store(): JSONResponse
	{
		$url = trim((string)$this->request->getParam('url', ''));
		$this->service->assertUrlAllowed($url); // SSRF gate at save AND delivery
		$events = $this->request->getParam('events', []);
		if (!is_array($events) || $events === []) {
			throw new ValidationException(['events' => 'Pick at least one event.']);
		}
		foreach ($events as $ev) {
			if (!in_array($ev, OutboundWebhookService::EVENTS, true)) {
				// a typo'd name subscribes to an event that can never fire —
				// reject it instead of storing a dead subscription
				throw new ValidationException(['events' => $this->tx('Unknown event') . ': ' . (string)$ev]);
			}
		}
		$w = new Webhook();
		$w->setUrl($url);
		$w->setSecretEnc($this->service->encryptSecret(bin2hex(random_bytes(24))));
		$w->setEventsJson(json_encode(array_values(array_map('strval', $events))) ?: '[]');
		$w->setActive(1);
		$w->setCreatedBy($this->userSession->getUser()?->getUID() ?? 'admin');
		$w->setCreatedAt(new \DateTimeImmutable());
		$this->webhooks->insert($w);
		return $this->ok(['id' => (int)$w->getId()], 201);
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse
	{
		$w = $this->webhooks->find($id);
		if ($w === null) {
			throw new NotFoundException();
		}
		if ($this->request->getParam('rotate_secret') === '1') {
			// dual-accept: previous secret signs for 24h while receivers swap
			$this->service->rotateSecret($w);
		}
		if ($this->request->getParam('url') !== null) {
			$url = trim((string)$this->request->getParam('url'));
			$this->service->assertUrlAllowed($url);
			$w->setUrl($url);
		}
		$this->webhooks->update($w);
		return $this->ok();
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse
	{
		$w = $this->webhooks->find($id);
		if ($w === null) {
			throw new NotFoundException();
		}
		$this->webhooks->delete($w);
		return $this->ok(['deleted' => true]);
	}
}

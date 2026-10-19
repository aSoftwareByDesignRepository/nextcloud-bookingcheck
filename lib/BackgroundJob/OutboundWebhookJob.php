<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\BackgroundJob;

use OCA\BookingCheck\Db\WebhookDeliveryMapper;
use OCA\BookingCheck\Db\WebhookMapper;
use OCA\BookingCheck\Service\OutboundWebhookService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Http\Client\IClientService;

class OutboundWebhookJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly WebhookDeliveryMapper $deliveries,
		private readonly WebhookMapper $webhooks,
		private readonly OutboundWebhookService $service,
		private readonly IClientService $clientService,
	) {
		parent::__construct($time);
		$this->setInterval(120);
	}

	protected function run($argument): void
	{
		$lease = new \DateTimeImmutable('+15 minutes');
		foreach ($this->deliveries->dueBatch(50) as $d) {
			$id = (int)$d->getId();
			$epoch = (int)$d->getAttempts() + 1; // post-claim claim count
			if (!$this->deliveries->claim($id, $lease)) {
				continue; // another worker took it
			}
			$w = $this->webhooks->find((int)$d->getWebhookId());
			if ($w === null) {
				// recipient deleted — dead-letter instead of retrying forever
				$this->service->markOrphaned($id, $epoch);
				continue;
			}
			if ((int)$w->getActive() !== 1) {
				// temporarily disabled — defer; the claim counts as a cycle,
				// so permanently-disabled deliveries dead-letter after 8
				$this->service->deferDelivery($id, $epoch);
				continue;
			}
			try {
				// re-validate at delivery — DNS may have changed since save
				$this->service->assertUrlAllowed($w->getUrl());
				$headers = ['Content-Type: application/json'];
				foreach ($this->service->signatureHeaders($w, $d->getPayloadJson()) as $h) {
					$headers[] = $h;
				}
				$client = $this->clientService->newClient();
				$client->post($w->getUrl(), [
					'headers' => $this->headerArray($headers),
					'body' => $d->getPayloadJson(),
					'timeout' => 10,
				]);
				$this->service->markDelivered($id, $epoch);
			} catch (\Throwable) {
				$this->service->markRetryOrDead($id, $epoch);
			}
		}
	}

	private function headerArray(array $lines): array
	{
		$out = [];
		foreach ($lines as $l) {
			[$k, $v] = explode(':', $l, 2);
			$out[trim($k)] = trim($v);
		}
		return $out;
	}
}

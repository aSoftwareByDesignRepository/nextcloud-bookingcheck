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
		foreach ($this->deliveries->dueBatch(50) as $d) {
			$w = $this->webhooks->find((int)$d->getWebhookId());
			if ($w === null || (int)$w->getActive() !== 1) {
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
				$this->service->markDelivered((int)$d->getId());
			} catch (\Throwable) {
				$this->service->markRetryOrDead((int)$d->getId());
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

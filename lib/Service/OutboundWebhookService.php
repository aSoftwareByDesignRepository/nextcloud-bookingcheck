<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\Db\Webhook;
use OCA\BookingCheck\Db\WebhookDelivery;
use OCA\BookingCheck\Db\WebhookDeliveryMapper;
use OCA\BookingCheck\Db\WebhookMapper;
use OCA\BookingCheck\Exception\ValidationException;
use OCP\Http\Client\IClientService;
use OCP\Security\ICrypto;

/**
 * Outbound webhooks (§9.13).
 *
 * SSRF: URL validated at save AND re-resolved at delivery — DNS can
 * change between the two. Signing: HMAC-SHA256 over the raw payload,
 * X-BookingCheck-Signature: sha256=<hex>. Secrets are ICrypto-encrypted
 * (they must be USED, so hashing is impossible). Delivery is job-driven;
 * the order txn only writes the bc_webhook_dlvr row.
 */
class OutboundWebhookService
{
	public const SIGNATURE_HEADER = 'X-BookingCheck-Signature';
	public const DUAL_ACCEPT_HOURS = 24;

	public function __construct(
		private readonly WebhookMapper $webhooks,
		private readonly WebhookDeliveryMapper $deliveries,
		private readonly IClientService $clientService,
		private readonly ICrypto $crypto,
	) {
	}

	/** Enqueue a domain event for every active webhook subscribed to it. */
	public function emit(string $event, array $payload): void
	{
		foreach ($this->webhooks->findAll() as $w) {
			if ((int)$w->getActive() !== 1) {
				continue;
			}
			$subs = json_decode($w->getEventsJson(), true);
			if (!is_array($subs) || !in_array($event, $subs, true)) {
				continue;
			}
			$d = new WebhookDelivery();
			$d->setWebhookId((int)$w->getId());
			$d->setEvent($event);
			$d->setPayloadJson(json_encode([
				'v' => 1, 'event' => $event, 'at' => date(\DateTimeInterface::ATOM),
				'data' => $payload,
			], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
			$d->setStatus('pending');
			$d->setAttempts(0);
			$d->setNextRetryAt(new \DateTimeImmutable());
			$d->setCreatedAt(new \DateTimeImmutable());
			$this->deliveries->insert($d);
		}
	}

	/** Sign with current secret; during rotation both signatures go out. */
	public function signatureHeaders(Webhook $w, string $payload): array
	{
		$headers = [self::SIGNATURE_HEADER . ': sha256=' .
			hash_hmac('sha256', $payload, $this->decryptSecret($w->getSecretEnc()))];
		if ($w->getSecretPrevEnc() !== null
			&& $w->getSecretRotatedAt() !== null
			&& $w->getSecretRotatedAt() > new \DateTimeImmutable('-' . self::DUAL_ACCEPT_HOURS . ' hours')) {
			$headers[] = self::SIGNATURE_HEADER . '-Previous: sha256=' .
				hash_hmac('sha256', $payload, $this->decryptSecret($w->getSecretPrevEnc()));
		}
		return $headers;
	}

	public function rotateSecret(Webhook $w): void
	{
		$w->setSecretPrevEnc($w->getSecretEnc());
		$w->setSecretEnc($this->encryptSecret(bin2hex(random_bytes(24))));
		$w->setSecretRotatedAt(new \DateTimeImmutable());
		$this->webhooks->update($w);
	}

	public function encryptSecret(string $plain): string
	{
		return $this->crypto->encrypt($plain);
	}

	public function decryptSecret(string $enc): string
	{
		$plain = $this->crypto->decrypt($enc);
		return is_string($plain) ? $plain : '';
	}

	/**
	 * SSRF gate — http(s) only, and the host must not resolve to a
	 * private/loopback/link-local address. Called at save and delivery.
	 */
	public function assertUrlAllowed(string $url): void
	{
		if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
			throw new ValidationException(['url' => 'Enter a valid http(s) URL.']);
		}
		$host = parse_url($url, PHP_URL_HOST);
		if (!is_string($host) || $host === '') {
			throw new ValidationException(['url' => 'Invalid host.']);
		}
		$ips = gethostbynamel($host);
		if ($ips === false || $ips === []) {
			// literal IP in the URL
			if (filter_var($host, FILTER_VALIDATE_IP)) {
				$ips = [$host];
			} else {
				throw new ValidationException(['url' => 'Host does not resolve.']);
			}
		}
		foreach ($ips as $ip) {
			if (!filter_var($ip, FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
				throw new ValidationException(['url' => 'Private/internal addresses are not allowed.']);
			}
		}
	}

	public function markDelivered(int $deliveryId): void
	{
		$d = $this->deliveries->find($deliveryId);
		if ($d !== null) {
			$d->setStatus('delivered');
			$d->setDeliveredAt(new \DateTimeImmutable());
			$this->deliveries->update($d);
		}
	}

	public function markRetryOrDead(int $deliveryId): void
	{
		$d = $this->deliveries->find($deliveryId);
		if ($d === null) {
			return;
		}
		$attempts = (int)$d->getAttempts() + 1;
		$d->setAttempts($attempts);
		if ($attempts >= 8) {
			$d->setStatus('dead');
		} else {
			// exponential backoff capped at 24h
			$d->setStatus('retry');
			$d->setNextRetryAt(new \DateTimeImmutable('+' . min(1440, (2 ** $attempts) * 5) . ' minutes'));
		}
		$this->deliveries->update($d);
	}
}

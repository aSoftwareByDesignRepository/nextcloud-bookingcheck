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

	/** Canonical event vocabulary — anything else is a dead subscription. */
	public const EVENTS = [
		'order.created', 'order.paid', 'order.failed', 'order.expired',
		'order.approved', 'order.declined', 'order.cancelled', 'order.refunded',
	];

	public function __construct(
		private readonly WebhookMapper $webhooks,
		private readonly WebhookDeliveryMapper $deliveries,
		private readonly IClientService $clientService,
		private readonly ICrypto $crypto,
		private readonly LicenseService $license,
		private readonly \OCP\IDBConnection $db,
	) {
	}

	/** Enqueue a domain event for every active webhook subscribed to it. */
	public function emit(string $event, array $payload): void
	{
		// Pro-gated — service-level backstop: order transitions call emit()
		// from free paths, and a pro→free downgrade must not keep firing
		if (!$this->license->hasFeature('outbound_webhooks')) {
			return;
		}
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
			], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
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
			throw new ValidationException(['url' => 'Enter a valid http or https URL.']);
		}
		$host = parse_url($url, PHP_URL_HOST);
		if (!is_string($host) || $host === '') {
			throw new ValidationException(['url' => 'Invalid host.']);
		}
		// IPv6 literals arrive bracketed ([::1]) — strip before IP validation
		if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
			$host = substr($host, 1, -1);
		}
		// A+AAAA both — gethostbynamel only returns IPv4, so a host with a
		// public A plus a private AAAA (::1, fe80::, fc00::) would pass the
		// check while the client prefers IPv6 and dials the internal target
		if (filter_var($host, FILTER_VALIDATE_IP)) {
			$ips = [$host];
		} else {
			$ips = [];
			foreach ((array)@dns_get_record($host, DNS_A | DNS_AAAA) as $rec) {
				$ips[] = (string)($rec['ip'] ?? $rec['ipv6'] ?? '');
			}
			$ips = array_values(array_filter($ips, static fn (string $ip) => $ip !== ''));
			if ($ips === []) {
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

	/** Final CAS — only the claim-epoch owner may flip the row. */
	public function markDelivered(int $deliveryId, int $epoch): void
	{
		$this->cas($deliveryId, $epoch, 'delivered', deliveredAt: new \DateTimeImmutable());
	}

	/** Webhook row deleted — the delivery can never reach anyone. */
	public function markOrphaned(int $deliveryId, int $epoch): void
	{
		$this->cas($deliveryId, $epoch, 'dead');
	}

	/**
	 * Webhook exists but is inactive — hold the delivery for later.
	 * Status 'retry' is required: dueBatch re-picks 'pending' regardless of
	 * next_retry_at, so deferral without it would not actually defer.
	 */
	public function deferDelivery(int $deliveryId, int $epoch, int $minutes = 30): void
	{
		$this->cas($deliveryId, $epoch, 'retry',
			nextRetryAt: new \DateTimeImmutable('+' . $minutes . ' minutes'));
	}

	/** Delivery failed — exponential backoff capped at 24h, dead at 8. */
	public function markRetryOrDead(int $deliveryId, int $epoch): void
	{
		if ($epoch >= 8) {
			$this->cas($deliveryId, $epoch, 'dead');
			return;
		}
		$this->cas($deliveryId, $epoch, 'retry',
			nextRetryAt: new \DateTimeImmutable('+' . min(1440, (2 ** $epoch) * 5) . ' minutes'));
	}

	/** CAS against the claim epoch — a stale worker's write is a no-op. */
	private function cas(int $id, int $epoch, string $status,
		?\DateTimeImmutable $deliveredAt = null, ?\DateTimeImmutable $nextRetryAt = null): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_webhook_dlvr')
			->set('status', $qb->createNamedParameter($status))
			->set('delivered_at', $qb->createNamedParameter($deliveredAt, 'datetime_immutable'))
			->set('next_retry_at', $qb->createNamedParameter($nextRetryAt, 'datetime_immutable'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('delivering')))
			->andWhere($qb->expr()->eq('attempts', $qb->createNamedParameter($epoch)));
		$qb->executeStatement();
	}
}

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCP\Http\Client\IClientService;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * NH-21 — push notifications to companion devices (manager role).
 *
 * Transport: Expo Push API — the only channel a store-compliant RN app
 * gets without per-OS vendor plumbing. Tokens are registered by devices
 * via /capi/v1/push and live on bc_companion. Failures are logged, never
 * retried inline — a dead push endpoint must not stall the order path;
 * receipts are best-effort (Expo keeps its own retry).
 */
class PushService
{
	private const EXPO_PUSH_URL = 'https://exp.host/--/api/v2/push/send';
	private const MAX_BATCH = 100; // Expo limit per request

	public function __construct(
		private readonly IClientService $clientService,
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Notify manager devices of an order event. Silent on any failure —
	 * push is a courtesy channel, email remains the authoritative one.
	 */
	public function notifyManagers(string $type, array $data): void
	{
		$tokens = $this->managerTokens();
		if ($tokens === []) {
			return;
		}
		$title = match ($type) {
			'order.paid' => 'New paid booking',
			'order.cancelled' => 'Booking cancelled',
			'order.refunded' => 'Refund issued',
			'abuse.reported' => 'Content report',
			default => 'BookingCheck',
		};
		// never put buyer PII in the notification payload — lock screens leak
		$body = match ($type) {
			'order.paid' => 'Order ' . ($data['order_ref'] ?? '') . ' paid.',
			'order.cancelled' => 'Order ' . ($data['order_ref'] ?? '') . ' was cancelled.',
			'order.refunded' => 'Order ' . ($data['order_ref'] ?? '') . ' was refunded.',
			'abuse.reported' => 'An attendee reported shared content for review.',
			default => 'Open BookingCheck.',
		};
		foreach (array_chunk($tokens, self::MAX_BATCH) as $chunk) {
			$messages = array_map(fn ($t) => [
				'to' => $t, 'title' => $title, 'body' => $body,
				'data' => ['type' => $type],
			], $chunk);
			$this->send($messages);
		}
	}

	/** @return list<string> */
	private function managerTokens(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('push_token')->from('bc_companion')
			->where($qb->expr()->eq('role', $qb->createNamedParameter('manager')))
			->andWhere($qb->expr()->isNull('revoked_at'))
			->andWhere($qb->expr()->isNotNull('push_token'))
			->andWhere($qb->expr()->eq('push_kind', $qb->createNamedParameter('expo')));
		return array_map('strval', array_column($qb->executeQuery()->fetchAll(), 'push_token'));
	}

	private function send(array $messages): void
	{
		try {
			$this->clientService->newClient()->post(self::EXPO_PUSH_URL, [
				'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
				'body' => json_encode($messages),
				'timeout' => 10,
			]);
		} catch (\Throwable $e) {
			$this->logger->info('bookingcheck push send failed', ['error' => $e->getMessage()]);
		}
	}
}

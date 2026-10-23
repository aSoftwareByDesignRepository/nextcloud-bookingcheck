<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\AppInfo\Application;
use OCA\BookingCheck\Support\Locales;
use OCP\Http\Client\IClientService;
use OCP\IDBConnection;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * NH-21 — push notifications to companion devices (manager role).
 *
 * Transport: Expo Push API — the only channel a store-compliant RN app
 * gets without per-OS vendor plumbing. Tokens are registered by devices
 * via /capi/v1/push and live on bc_companion. Failures are logged, never
 * retried inline — a dead push endpoint must not stall the order path;
 * receipts are best-effort (Expo keeps its own retry).
 *
 * Payloads render on the device verbatim (system notification), so
 * title/body are resolved per-recipient language: the device's own
 * locale reported at push registration, else the shop's configured
 * default_locale (l10n_unlocalized_error_surface — English payloads
 * on non-English devices were the defect).
 */
class PushService
{
	private const EXPO_PUSH_URL = 'https://exp.host/--/api/v2/push/send';
	private const MAX_BATCH = 100; // Expo limit per request

	public function __construct(
		private readonly IClientService $clientService,
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		private readonly IFactory $l10nFactory,
		private readonly SettingsService $settings,
	) {
	}

	/**
	 * Notify manager devices of an order event. Silent on any failure —
	 * push is a courtesy channel, email remains the authoritative one.
	 */
	public function notifyManagers(string $type, array $data): void
	{
		$defaultLocale = Locales::canonicalize($this->settings->get('default_locale'));
		$byLocale = [];
		foreach ($this->managerTargets() as $target) {
			$byLocale[$target['locale'] !== '' ? $target['locale'] : $defaultLocale][] = $target['token'];
		}
		$ref = (string)($data['order_ref'] ?? '');
		foreach ($byLocale as $locale => $tokens) {
			$l = $this->l10nFactory->get(Application::APP_ID, $locale);
			[$title, $body] = match ($type) {
				'order.paid' => [$l->t('New paid booking'), $l->t('Order %s paid.', [$ref])],
				'order.cancelled' => [$l->t('Booking cancelled'), $l->t('Order %s was cancelled.', [$ref])],
				'order.refunded' => [$l->t('Refund issued'), $l->t('Order %s was refunded.', [$ref])],
				'abuse.reported' => [$l->t('Content report'), $l->t('An attendee reported shared content for review.')],
				// brand title stays literal; the body still translates
				default => ['BookingCheck', $l->t('Open BookingCheck.')],
			};
			foreach (array_chunk($tokens, self::MAX_BATCH) as $chunk) {
				$messages = array_map(fn ($t) => [
					'to' => $t, 'title' => $title, 'body' => $body,
					'data' => ['type' => $type],
				], $chunk);
				$this->send($messages);
			}
		}
	}

	/** @return list<array{token: string, locale: string}> */
	private function managerTargets(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('push_token', 'locale')->from('bc_companion')
			->where($qb->expr()->eq('role', $qb->createNamedParameter('manager')))
			->andWhere($qb->expr()->isNull('revoked_at'))
			->andWhere($qb->expr()->isNotNull('push_token'))
			->andWhere($qb->expr()->eq('push_kind', $qb->createNamedParameter('expo')));
		return array_map(
			fn (array $r): array => [
				'token' => (string)$r['push_token'],
				// '' = never reported → notifyManagers falls back to the
				// shop default_locale (canonicalize('') would pin 'en')
				'locale' => trim((string)($r['locale'] ?? '')) === ''
					? ''
					: Locales::canonicalize($r['locale']),
			],
			$qb->executeQuery()->fetchAll());
	}

	/** @param list<array<string,mixed>> $messages */
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

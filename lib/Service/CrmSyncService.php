<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\Db\Order;
use OCA\BookingCheck\Db\SyncTaskMapper;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * US-525: push buyers into customercheck via its own order-webhook service
 * — same code path as POST /apps/customercheck/hooks/order/{token}
 * (token-validated, rate-limited, idempotent by orderId). In-process class
 * probe, not HTTP: a self-request depends on the instance's external URL
 * being reachable from inside the host, which is not guaranteed (containers,
 * split DNS). Family precedent: FacadeProbeService class-probing.
 *
 * Non-fatal by design: enqueueing happens inside the paid transaction (a
 * queue row), delivery happens in CrmSyncJob. If customercheck is absent,
 * disabled, or errors — the order flow is never touched.
 */
class CrmSyncService
{
	/** The sibling's own entry point — probed, never hard-imported. */
	private const SIBLING_SERVICE = 'OCA\\CustomerCheck\\Service\\WebsiteOrderWebhookService';

	public function __construct(
		private readonly SyncTaskMapper $tasks,
		private readonly SettingsService $settings,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
		private readonly LicenseService $license,
	) {
	}

	/** Settings UI gate: the toggle only exists while the sibling is enabled. */
	public function isAvailable(): bool
	{
		return $this->appManager->isEnabledForUser('customercheck')
			&& class_exists(self::SIBLING_SERVICE);
	}

	public function isConfigured(): bool
	{
		return $this->isAvailable()
			&& $this->license->hasFeature('customercheck_sync') // Pro-gated
			&& $this->settings->get('crm_sync_enabled') === '1'
			&& $this->settings->get('crm_sync_token') !== '';
	}

	/**
	 * Queue a buyer sync — called inside the paid transaction; the durable
	 * row survives crashes, delivery is async. Buyer-only per spec (AC-4):
	 * attendees are never synced.
	 */
	public function enqueueBuyerSync(Order $order, int $seats, string $offeringTitle): void
	{
		if (!$this->isConfigured() || (int)$order->getIsTest() === 1) {
			return; // test-mode orders never become CRM contacts
		}
		$paidAt = $order->getPaidAt() ?? new \DateTimeImmutable();
		$this->tasks->insertTask('crm_buyer', [
			'order_ref' => (string)$order->getRef(),
			'email' => (string)$order->getBuyerEmail(),
			'name' => (string)$order->getBuyerName(),
			'company' => (string)($order->getBuyerCompany() ?? ''),
			'locale' => (string)($order->getBuyerLocale() ?: 'en'),
			'offering' => $offeringTitle,
			'seats' => max(1, $seats),
			'amount_cents' => (int)$order->getAmountCents(),
			'paid_date' => $paidAt->format('Y-m-d'),
		]);
	}

	/**
	 * Deliver one queued task to customercheck's own webhook service.
	 * Returns true when the sibling accepted. Any failure → caller retries;
	 * sync is a copy of already-committed truth, so at-least-once is correct
	 * (the sibling dedupes on orderId).
	 */
	public function deliver(array $payload): bool
	{
		if (!$this->isConfigured()) {
			return false;
		}
		$token = $this->settings->get('crm_sync_token');
		$paidDate = (string)($payload['paid_date'] ?? date('Y-m-d'));
		$body = [
			// orderId doubles as the sibling's idempotency key
			'orderId' => 'bck-' . (string)($payload['order_ref'] ?? ''),
			// CRM requires a company; private buyers map name→company (documented)
			'companyName' => ($payload['company'] ?? '') !== ''
				? (string)$payload['company'] : (string)$payload['name'],
			'email' => (string)$payload['email'],
			'contactName' => (string)$payload['name'],
			'phone' => '',
			'lang' => str_starts_with((string)($payload['locale'] ?? 'en'), 'de') ? 'de' : 'en',
			// the sibling's hook is license-sale shaped — a paid booking maps
			// to one agreement line: product, seat count, paid amount, and a
			// 12-month record window from the payment date
			'agreements' => [[
				'productCode' => 'bookingcheck',
				'label' => mb_substr('Booking: ' . (string)($payload['offering'] ?? ''), 0, 190),
				'seats' => (int)($payload['seats'] ?? 1),
				'devices' => 0,
				'amountCents' => (int)($payload['amount_cents'] ?? 0),
				'startsAt' => $paidDate,
				'validUntil' => (new \DateTimeImmutable($paidDate))->modify('+12 months')->format('Y-m-d'),
			]],
		];
		try {
			$this->callSibling($token, $body);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('bookingcheck: CRM sync delivery failed', [
				'order' => $payload['order_ref'] ?? '?', 'error' => $e->getMessage(),
			]);
			return false;
		}
	}

	/** Isolated probe point — mocked in tests; the sibling class may not exist. */
	protected function callSibling(string $token, array $body): void
	{
		\OCP\Server::get(self::SIBLING_SERVICE)->handle($token, '127.0.0.1', $body);
	}
}

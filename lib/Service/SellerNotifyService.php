<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\Db\Order;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\L10N\IFactory as IL10NFactory;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * US-528: seller notification on paid orders — NC notification + email.
 *
 * - Event-driven from the paid transition only (no state scan) → deduped
 *   by construction; webhook replays never re-notify.
 * - Per-user opt-out honoured (user config `notify_paid`, default ON).
 * - Test-mode orders are marked TEST in subject params.
 * - Rate cap: past MAX_PER_HOUR notifications/hour the NC ping is skipped
 *   (the email still queues through its own capping) — never self-spam.
 */
class SellerNotifyService
{
	private const MAX_PER_HOUR = 20;
	private const APP_ID = 'bookingcheck';

	public function __construct(
		private readonly INotificationManager $notifications,
		private readonly EmailService $email,
		private readonly SettingsService $settings,
		private readonly AuditService $audit,
		private readonly IConfig $config,
		private readonly IUserManager $users,
		private readonly IL10NFactory $l10nFactory,
		private readonly LoggerInterface $logger,
	) {
	}

	public function orderPaid(Order $order, string $sellerUid, string $offeringTitle): void
	{
		if ($sellerUid === '' || !$this->wantsPaid($sellerUid)) {
			return;
		}
		$isTest = (int)$order->getIsTest() === 1;
		try {
			if ($this->underCap($sellerUid)) {
				$n = $this->notifications->createNotification();
				$n->setApp(self::APP_ID)
					->setUser($sellerUid)
					->setDateTime(new \DateTime())
					->setObject('order', (string)$order->getId())
					->setSubject('order_paid', [
						'ref' => (string)$order->getRef(),
						'offering' => $offeringTitle,
						'test' => $isTest ? '1' : '0',
					]);
				$this->notifications->notify($n);
				$this->audit->log((int)$order->getId(), (int)$order->getOfferingId(), $sellerUid,
					'notify.sent', null, 'paid', ['channel' => 'nc']);
			}
			// the seller reads mail in THEIR configured UI language, not
			// the buyer's and not hardcoded English
			$this->email->queue('seller_order_paid', $this->settings->get('seller_email'),
				$this->l10nFactory->getUserLanguage($this->users->get($sellerUid)), [
				'order_ref' => (string)$order->getRef(),
				'offering' => $offeringTitle,
				'amount' => (int)$order->getAmountCents() / 100,
				'currency' => (string)$order->getCurrency(),
				'test' => $isTest,
			], capped: true);
		} catch (\Throwable $e) {
			// notifications must never break the paid transition
			$this->logger->warning('bookingcheck: seller notify failed', ['error' => $e->getMessage()]);
		}
	}

	/** Approval requests always notify — they need seller action (US-528). */
	public function approvalNeeded(Order $order, string $sellerUid, string $offeringTitle): void
	{
		if ($sellerUid === '') {
			return;
		}
		try {
			$n = $this->notifications->createNotification();
			$n->setApp(self::APP_ID)
				->setUser($sellerUid)
				->setDateTime(new \DateTime())
				->setObject('order', (string)$order->getId())
				->setSubject('approval_needed', [
					'ref' => (string)$order->getRef(),
					'offering' => $offeringTitle,
					'test' => (int)$order->getIsTest() === 1 ? '1' : '0',
				]);
			$this->notifications->notify($n);
		} catch (\Throwable $e) {
			$this->logger->warning('bookingcheck: approval notify failed', ['error' => $e->getMessage()]);
		}
	}

	public function wantsPaid(string $uid): bool
	{
		return $this->config->getUserValue($uid, self::APP_ID, 'notify_paid', '1') === '1';
	}

	public function setWantsPaid(string $uid, bool $on): void
	{
		$this->config->setUserValue($uid, self::APP_ID, 'notify_paid', $on ? '1' : '0');
	}

	/** Count our notify.audit rows in the last hour for the seller. */
	private function underCap(string $uid): bool
	{
		return $this->audit->countRecentActions('notify.sent', $uid, 3600) < self::MAX_PER_HOUR;
	}
}

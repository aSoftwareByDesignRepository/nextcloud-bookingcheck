<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\Db\Order;
use OCA\BookingCheck\Db\Payment;
use OCA\BookingCheck\Db\PaymentMapper;
use OCA\BookingCheck\Psp\CheckoutSession;
use OCA\BookingCheck\Psp\PspManager;
use OCP\IURLGenerator;

/**
 * Hosted-checkout session creation — shared by first-checkout and the
 * manage-page "pay now" path for approved orders. Session creation is a
 * network call and MUST stay outside the order DB transaction.
 */
class PaySessionService
{
	public function __construct(
		private readonly PspManager $psp,
		private readonly PaymentMapper $payments,
		private readonly SettingsService $settings,
		private readonly IURLGenerator $url,
	) {
	}

	public function createForOrder(Order $order): CheckoutSession
	{
		$provider = $this->settings->get('psp_provider');
		$session = $this->psp->forCheckout()->createCheckout(
			$order,
			$this->url->linkToRouteAbsolute('bookingcheck.pubCheckout.return',
				['publicRef' => $order->getRef()]),
			$this->url->linkToRouteAbsolute('bookingcheck.psp.' . $provider),
		);
		$pay = new Payment();
		$pay->setOrderId((int)$order->getId());
		$pay->setProvider($provider);
		$pay->setPspRef($session->pspRef);
		$pay->setStatus('open');
		$pay->setAmountCents((int)$order->getAmountCents());
		$pay->setCreatedAt(new \DateTimeImmutable());
		$this->payments->insert($pay);
		return $session;
	}
}

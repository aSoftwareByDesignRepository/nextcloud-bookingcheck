<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Notification;

use OCA\BookingCheck\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier
{
	public function __construct(
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string
	{
		return Application::APP_ID;
	}

	public function getName(): string
	{
		return $this->l10nFactory->get(Application::APP_ID)->t('BookingCheck');
	}

	public function prepare(INotification $notification, string $languageCode): INotification
	{
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$p = $notification->getSubjectParameters();
		$ref = (string)($p['ref'] ?? '');
		$title = (string)($p['offering'] ?? '');
		$isTest = ($p['test'] ?? '0') === '1';
		$prefix = $isTest ? '[' . $l->t('TEST') . '] ' : '';
		$link = $this->urlGenerator->linkToRouteAbsolute('bookingcheck.order.show', [
			'id' => $notification->getObjectId(),
		]);

		switch ($notification->getSubject()) {
			case 'order_paid':
				$notification->setParsedSubject($prefix . $l->t('New paid booking %s', [$ref]));
				$notification->setParsedMessage($title !== '' ? $title : $l->t('Open BookingCheck for details.'));
				$notification->setLink($link);
				return $notification;
			case 'approval_needed':
				$notification->setParsedSubject($prefix . $l->t('Booking %s needs approval', [$ref]));
				$notification->setParsedMessage($l->t('Review and approve or decline it in BookingCheck.'));
				$notification->setLink($link);
				return $notification;
			default:
				throw new UnknownNotificationException();
		}
	}
}

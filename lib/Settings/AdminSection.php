<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection
{
	public function __construct(
		private readonly IL10N $l,
		private readonly IURLGenerator $url,
	) {
	}

	public function getID(): string
	{
		return 'bookingcheck';
	}

	public function getName(): string
	{
		return $this->l->t('BookingCheck');
	}

	public function getPriority(): int
	{
		return 55;
	}

	public function getIcon(): string
	{
		return $this->url->imagePath('bookingcheck', 'app-dark.svg');
	}
}

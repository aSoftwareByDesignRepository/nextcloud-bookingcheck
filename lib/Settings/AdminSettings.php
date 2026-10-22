<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Settings;

use OCA\BookingCheck\Support\AssetRegistrar;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

/**
 * NC administration settings surface — a pointer only.
 *
 * BookingCheck configuration lives on the in-app settings pages
 * (/apps/bookingcheck/settings/{section}) so that delegated app
 * administrators — who are not Nextcloud sysadmins and cannot open this
 * page — can manage payments, invoicing, access policy and license.
 */
class AdminSettings implements ISettings
{
	public function __construct(
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function getForm(): TemplateResponse
	{
		// NC settings requests bypass AppAccessMiddleware (no /apps/ prefix)
		// — without this the template's bck-* classes render unstyled.
		AssetRegistrar::registerStyles();
		return new TemplateResponse('bookingcheck', 'settings/admin', [
			'settingsUrl' => $this->urlGenerator->linkToRoute('bookingcheck.page.settings'),
		]);
	}

	public function getSection(): string
	{
		return 'bookingcheck';
	}

	public function getPriority(): int
	{
		return 10;
	}
}

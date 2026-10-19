<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings
{
	public function __construct(
		private readonly IAppConfig $config,
		private readonly string $userId,
	) {
	}

	public function getForm(): TemplateResponse
	{
		Util::addScript('bookingcheck', 'bookingcheck-adminSettings');
		return new TemplateResponse('bookingcheck', 'settings/admin', [
			'pspProvider' => $this->config->getAppValue('psp_provider', 'mollie'),
			'hasMollieKey' => $this->config->getAppValue('mollie_api_key_enc', '') !== '',
			'hasStripeKey' => $this->config->getAppValue('stripe_secret_key_enc', '') !== '',
			'companyName' => $this->config->getAppValue('seller_name', ''),
			'companyAddress' => $this->config->getAppValue('seller_address', ''),
			'companyVatId' => $this->config->getAppValue('seller_vat_id', ''),
			'licenseKey' => $this->config->getAppValue('license_key', ''),
			'licenseState' => $this->config->getAppValue('license_state', 'free'),
			'directoryEnabled' => $this->config->getAppValue('directory_enabled', '0'),
			'corsOrigins' => $this->config->getAppValue('cors_origins', ''),
			'portalEnabled' => $this->config->getAppValue('portal_enabled', '0'),
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

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Settings;

use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IConfig;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings
{
	public function __construct(
		private readonly IAppConfig $config,
		private readonly ?string $userId,
		private readonly IAppManager $appManager,
		private readonly IConfig $serverConfig,
		private readonly \OCA\BookingCheck\Service\LicenseService $license,
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
			'companyCountry' => $this->config->getAppValue('seller_country', 'DE'),
			'kleinunternehmer' => $this->config->getAppValue('kleinunternehmer', '0'),
			'viesOnline' => $this->config->getAppValue('vies_online', '0'),
			'licenseKey' => $this->config->getAppValue('license_key', ''),
			// live codec verdict — never a stale stored flag
			'licenseState' => $this->license->status()['state'],
			'directoryEnabled' => $this->config->getAppValue('directory_enabled', '0'),
			'corsOrigins' => $this->config->getAppValue('cors_origins', ''),
			'portalEnabled' => $this->config->getAppValue('portal_enabled', '0'),
			// US-525: CRM sync controls only render while customercheck exists
			'crmAvailable' => $this->appManager->isEnabledForUser('customercheck'),
			'crmSyncEnabled' => $this->config->getAppValue('crm_sync_enabled', '0'),
			'hasCrmToken' => $this->config->getAppValue('crm_sync_token_enc', '') !== '',
			// US-528: per-user paid-order notification toggle
			'notifyPaid' => $this->userId !== null
				? $this->serverConfig->getUserValue($this->userId, 'bookingcheck', 'notify_paid', '1')
				: '1',
			// NH-02 alternative PSPs — IDs plain, secrets flag-only
			'hasSumupKey' => $this->config->getAppValue('sumup_api_key_enc', '') !== '',
			'sumupMerchant' => $this->config->getAppValue('sumup_merchant_code', ''),
			'paypalClientId' => $this->config->getAppValue('paypal_client_id', ''),
			'hasPaypalSecret' => $this->config->getAppValue('paypal_client_secret_enc', '') !== '',
			'paypalSandbox' => $this->config->getAppValue('paypal_sandbox', '1'),
			'klarnaUid' => $this->config->getAppValue('klarna_api_uid', ''),
			'hasKlarnaSecret' => $this->config->getAppValue('klarna_api_password_enc', '') !== '',
			'klarnaSandbox' => $this->config->getAppValue('klarna_sandbox', '1'),
			// NH-01 OSS + NH-05 DATEV
			'ossEnabled' => $this->config->getAppValue('oss_enabled', '0'),
			'ossVatMap' => $this->config->getAppValue('oss_vat_map', ''),
			'datevConsultant' => $this->config->getAppValue('datev_consultant_no', ''),
			'datevClient' => $this->config->getAppValue('datev_client_no', ''),
			// NH-22 feed + NH-13 AV
			'icalFeedToken' => $this->config->getAppValue('ical_feed_token', ''),
			'icalFeedUrl' => $this->config->getAppValue('ical_feed_token', '') !== ''
				? \OCP\Server::get(\OCP\IURLGenerator::class)->linkToRouteAbsolute(
					'bookingcheck.ical.seller', ['token' => $this->config->getAppValue('ical_feed_token', '')])
				: '',
			'avEnabled' => $this->config->getAppValue('av_enabled', '0'),
			'avIcapEndpoint' => $this->config->getAppValue('av_icap_endpoint', ''),
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

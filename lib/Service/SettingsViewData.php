<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCP\App\IAppManager;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * View-model assembly for the in-app settings pages — every template
 * variable the settings sections read, in one place. Secrets surface as
 * `hasX` flags only; plaintext never reaches the HTML.
 */
class SettingsViewData
{
	public function __construct(
		private readonly IAppConfig $config,
		private readonly ?string $userId,
		private readonly IAppManager $appManager,
		private readonly IConfig $serverConfig,
		private readonly LicenseService $license,
		private readonly \OCA\BookingCheck\Db\MailTplMapper $mailTpls,
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	/** @return array<string,mixed> template params for settings pages */
	public function get(): array
	{
		return [
			'pspProvider' => $this->config->getAppValue('psp_provider', 'mollie'),
			'hasMollieKey' => $this->config->getAppValue('mollie_api_key_enc', '') !== '',
			'hasStripeKey' => $this->config->getAppValue('stripe_secret_key_enc', '') !== '',
			'companyName' => $this->config->getAppValue('seller_name', ''),
			'sellerEmail' => $this->config->getAppValue('seller_email', ''),
			'invoicePrefix' => $this->config->getAppValue('invoice_prefix', 'INV'),
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
				? $this->urlGenerator->linkToRouteAbsolute(
					'bookingcheck.ical.seller', ['token' => $this->config->getAppValue('ical_feed_token', '')])
				: '',
			'avEnabled' => $this->config->getAppValue('av_enabled', '0'),
			'avIcapEndpoint' => $this->config->getAppValue('av_icap_endpoint', ''),
			// flags only for the remaining secrets — plaintext never leaves the server
			'hasMollieTestKey' => $this->config->getAppValue('mollie_test_key_enc', '') !== '',
			'hasMollieWebhookSecret' => $this->config->getAppValue('mollie_webhook_secret_enc', '') !== '',
			'hasStripeWebhookSecret' => $this->config->getAppValue('stripe_webhook_secret_enc', '') !== '',
			// flows — booking lifecycle TTLs
			'orderHoldMin' => $this->config->getAppValue('order_hold_min', ''),
			'approvalHoldMin' => $this->config->getAppValue('approval_hold_min', ''),
			'paymentTtlMin' => $this->config->getAppValue('payment_ttl_min', ''),
			'otpTtlMin' => $this->config->getAppValue('otp_ttl_min', ''),
			'portalSessDays' => $this->config->getAppValue('portal_sess_days', ''),
			'waitlistOfferH' => $this->config->getAppValue('waitlist_offer_h', ''),
			'defaultLocale' => $this->config->getAppValue('default_locale', ''),
			// NH-30 wallet adapter — IDs plain, cert/key flag-only
			'walletGoogleIssuer' => $this->config->getAppValue('wallet_google_issuer', ''),
			'walletGoogleSaEmail' => $this->config->getAppValue('wallet_google_sa_email', ''),
			'hasWalletGoogleSecret' => $this->config->getAppValue('wallet_google_secret_enc', '') !== '',
			'walletApplePassTypeId' => $this->config->getAppValue('wallet_apple_pass_type_id', ''),
			'walletAppleTeamId' => $this->config->getAppValue('wallet_apple_team_id', ''),
			'hasWalletAppleCert' => $this->config->getAppValue('wallet_apple_cert_pem_enc', '') !== '',
			'hasWalletAppleKey' => $this->config->getAppValue('wallet_apple_key_pem_enc', '') !== '',
			'datevRevenue' => $this->config->getAppValue('datev_revenue_account', ''),
			'datevReceivable' => $this->config->getAppValue('datev_receivable_account', ''),
			// NH-37 mail-template overrides — kind labels translated for the picker
			'mailTemplates' => array_map(
				static fn (\OCA\BookingCheck\Db\MailTpl $t) => [
					'id' => (int)$t->getId(),
					'kind' => (string)$t->getKind(),
					'locale' => (string)$t->getLocale(),
					'subject' => (string)($t->getSubject() ?? ''),
					'extra_text' => (string)($t->getExtraText() ?? ''),
				],
				$this->mailTpls->listAll()),
			'mailKindLabels' => $this->mailKindLabels(),
		];
	}

	/** @return list<array{0:string,1:string}> [kind, human label] pairs for the picker */
	private function mailKindLabels(): array
	{
		$l = $this->l10n;
		$labels = [
			'order_confirmed' => $l->t('Booking confirmed'),
			'payment_link' => $l->t('Booking approved — payment link'),
			'payment_failed' => $l->t('Payment failed'),
			'order_expired' => $l->t('Booking expired'),
			'order_declined' => $l->t('Booking request declined'),
			'approval_request' => $l->t('New booking request (to staff)'),
			'approval_pending' => $l->t('Booking request received'),
			'otp' => $l->t('Sign-in code'),
			'waitlist_offer' => $l->t('Waitlist seat offer'),
			'waitlist_notify' => $l->t('Waitlist seat notification'),
			'manage_link' => $l->t('Manage-booking link'),
			'broadcast' => $l->t('Broadcast update'),
			'rescheduled' => $l->t('Booking moved'),
			'seller_order_paid' => $l->t('Paid order (to staff)'),
		];
		$out = [];
		foreach (MailRenderer::KINDS as $k) {
			$out[] = [$k, $labels[$k] ?? $k];
		}
		return $out;
	}
}

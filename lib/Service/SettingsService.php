<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\AppInfo\Application;
use OCP\IConfig;
use OCP\Security\ICrypto;

/**
 * App settings. Secrets (PSP keys, webhook secrets) are stored ONLY through
 * the crypto accessors — a config/DB dump must never yield working payment
 * credentials.
 */
class SettingsService
{
	private const DEFAULTS = [
		'psp_provider'       => '',        // mollie|stripe|test|'' (unconfigured; 'test' only via occ)
		'psp_test_secret'    => '',        // HMAC key for the §9.13 sandbox provider

		'invoice_prefix'     => 'INV',
		'order_hold_min'     => '15',
		'approval_hold_min'  => '4320',    // 72h
		'payment_ttl_min'    => '1440',    // 24h after approval
		'otp_ttl_min'        => '10',
		'otp_max_attempts'   => '5',
		'portal_sess_days'   => '30',
		'waitlist_offer_h'   => '24',
		'seller_name'        => '',
		'seller_email'       => '',
		'seller_address'     => '',
		'seller_vat_id'      => '',
		'seller_country'     => 'DE',
		'kleinunternehmer'   => '0',
		'directory_enabled'  => '0',
		'cors_origins'       => '',
		'default_locale'     => 'en',
		'crm_sync_enabled'   => '0',
		'crm_sync_token'     => '',
		'vies_online'        => '0',       // '1' = live VIES REST verdicts; '0' = format-only

		// NH-01 OSS VAT — JSON {CC: basis-points} applied to B2C orders by
		// billing country when enabled; seller-home rate stays the default
		'oss_enabled'        => '0',
		'oss_vat_map'        => '',
		// NH-06 DATEV
		'datev_consultant_no' => '0',
		'datev_client_no'    => '0',
		'datev_revenue_account' => '8400',
		'datev_receivable_account' => '1400',
		// NH-13 AV
		'av_enabled'         => '0',
		'av_icap_endpoint'   => '',
		// NH-30 wallet
		'wallet_google_issuer' => '',
		'wallet_google_sa_email' => '',
		'wallet_apple_pass_type_id' => '',
		'wallet_apple_team_id' => '',
		// NH-02 PSP config
		'sumup_merchant_code' => '',
		'paypal_sandbox'     => '0',
		'klarna_sandbox'     => '0',
		// NH-22 public iCal feeds — random token enables the seller feed
		'ical_feed_token'    => '',
	];

	// Keys that must only ever be written/read through the crypto pair.
	public const SECRET_KEYS = [
		'mollie_api_key', 'mollie_test_key', 'stripe_secret_key',
		'stripe_webhook_secret', 'mollie_webhook_secret', 'crm_sync_token',
		'sumup_api_key', 'paypal_client_secret', 'klarna_api_password',
		'wallet_google_secret', 'wallet_apple_cert_pem', 'wallet_apple_key_pem',
	];

	public function __construct(
		private readonly IConfig $config,
		private readonly ICrypto $crypto,
	) {
	}

	public function get(string $key, ?string $default = null): string
	{
		if (in_array($key, self::SECRET_KEYS, true)) {
			return $this->getSecret($key);
		}
		return $this->config->getAppValue(Application::APP_ID, $key, $default ?? (self::DEFAULTS[$key] ?? ''));
	}

	public function set(string $key, string $value): void
	{
		if (in_array($key, self::SECRET_KEYS, true)) {
			$this->setSecret($key, $value);
			return;
		}
		$this->config->setAppValue(Application::APP_ID, $key, $value);
	}

	public function getSecret(string $key): string
	{
		$enc = $this->config->getAppValue(Application::APP_ID, $key . '_enc', '');
		if ($enc === '') {
			return '';
		}
		$plain = $this->crypto->decrypt($enc);
		return is_string($plain) ? $plain : '';
	}

	public function setSecret(string $key, string $plain): void
	{
		if ($plain === '') {
			$this->config->deleteAppValue(Application::APP_ID, $key . '_enc');
			return;
		}
		$this->config->setAppValue(Application::APP_ID, $key . '_enc', $this->crypto->encrypt($plain));
	}

	/**
	 * The ONE normaliser for non-secret settings writes — the settings
	 * controller and the config-transfer hooks share it (NN-08 parity).
	 * Returns the exact string {@see set()} would persist.
	 *
	 * @throws \InvalidArgumentException 'UNKNOWN_SETTING' for keys outside
	 *         the writable set; a human-readable message otherwise
	 */
	public function normalizeForWrite(string $key, string $value): string
	{
		if (!array_key_exists($key, self::DEFAULTS) && $key !== 'license_key') {
			throw new \InvalidArgumentException('UNKNOWN_SETTING');
		}
		if ($key === 'psp_provider'
			&& !in_array($value, ['', 'mollie', 'stripe', 'sumup', 'paypal', 'klarna'], true)) {
			throw new \InvalidArgumentException('Unknown provider.');
		}
		if (in_array($key, ['kleinunternehmer', 'vies_online', 'directory_enabled',
			'crm_sync_enabled', 'portal_enabled', 'oss_enabled', 'av_enabled',
			'paypal_sandbox', 'klarna_sandbox'], true)) {
			return in_array($value, ['1', 'true'], true) ? '1' : '0';
		}
		if ($key === 'seller_country' && !preg_match('/^[A-Z]{2}$/', $value)) {
			throw new \InvalidArgumentException('ISO country code.');
		}
		if ($key === 'seller_email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
			throw new \InvalidArgumentException('Valid email address expected.');
		}
		if ($key === 'invoice_prefix' && !preg_match('/^[A-Za-z0-9\-]{0,20}$/', $value)) {
			throw new \InvalidArgumentException('Letters, digits and dashes only.');
		}
		if ($key === 'default_locale' && $value !== '' && !preg_match('/^[a-z]{2}$/', $value)) {
			throw new \InvalidArgumentException('Language code like en or de.');
		}
		if (in_array($key, ['order_hold_min', 'approval_hold_min', 'payment_ttl_min',
			'otp_ttl_min', 'portal_sess_days', 'waitlist_offer_h'], true)) {
			if (!is_numeric($value) || (int)$value < 1 || (int)$value > 525600) {
				throw new \InvalidArgumentException('Must be a positive duration.');
			}
			return (string)(int)$value;
		}
		if ($key === 'otp_max_attempts') {
			if (!is_numeric($value) || (int)$value < 1 || (int)$value > 100) {
				throw new \InvalidArgumentException('Must be a positive attempt count.');
			}
			return (string)(int)$value;
		}
		if ($key === 'oss_vat_map' && trim($value) !== '') {
			$map = json_decode($value, true);
			if (!is_array($map)) {
				throw new \InvalidArgumentException('JSON map of country code → VAT basis points.');
			}
			foreach ($map as $cc => $bp) {
				if (!preg_match('/^[A-Z]{2}$/', (string)$cc) || !is_numeric($bp)
					|| (int)$bp < 0 || (int)$bp > 10000) {
					throw new \InvalidArgumentException('Keys are ISO country codes; values 0–10000 bp.');
				}
			}
			return $value;
		}
		if ($key === 'ical_feed_token' && $value !== '' && !preg_match('/^[A-Za-z0-9_-]{16,64}$/', $value)) {
			throw new \InvalidArgumentException('Token must be 16–64 url-safe chars.');
		}
		if ($key === 'av_icap_endpoint' && trim($value) !== ''
			&& !preg_match('/^[A-Za-z0-9.\-]+:\\d{1,5}$/', trim($value))) {
			throw new \InvalidArgumentException('host:port expected.');
		}
		if ($key === 'cors_origins' && trim($value) !== '') {
			foreach (preg_split('/[\s,]+/', trim($value)) ?: [] as $origin) {
				if ($origin === '*') {
					throw new \InvalidArgumentException('Wildcard origins are not allowed.');
				}
				$u = parse_url($origin);
				if (empty($u['host']) || !in_array($u['scheme'] ?? '', ['https', 'http'], true)
					|| (($u['scheme'] === 'http') && !in_array($u['host'], ['localhost', '127.0.0.1'], true))) {
					throw new \InvalidArgumentException('Only https:// origins (or http://localhost) are allowed.');
				}
			}
		}
		if (strlen($value) > 65536) {
			throw new \InvalidArgumentException('TOO_LONG');
		}
		return $value;
	}

	public function getInt(string $key, int $default = 0): int
	{
		$v = $this->get($key);
		return is_numeric($v) ? (int)$v : $default;
	}

	public function getBool(string $key): bool
	{
		// "false" string is truthy — normalize explicitly.
		return in_array($this->get($key), ['1', 'true', 'yes'], true);
	}

	/** @return array<string,string> non-secret settings for admin UI */
	public function publicSettings(): array
	{
		$out = [];
		foreach (self::DEFAULTS as $k => $d) {
			$out[$k] = $this->get($k);
		}
		foreach (self::SECRET_KEYS as $k) {
			$out[$k . '_set'] = $this->getSecret($k) !== '' ? '1' : '0';
		}
		return $out;
	}
}

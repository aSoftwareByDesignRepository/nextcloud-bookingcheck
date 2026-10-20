<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Exception\ValidationException;
use OCA\BookingCheck\Psp\PspManager;
use OCA\BookingCheck\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/** Admin settings JSON — secrets only ever written, never read back. */
class SettingsController extends Controller
{
	use ApiControllerTrait;

	private const SECTIONS = [
		'payments' => ['psp_provider', 'mollie_api_key', 'mollie_test_key',
			'stripe_secret_key', 'stripe_webhook_secret', 'mollie_webhook_secret',
			'sumup_api_key', 'sumup_merchant_code',
			'paypal_client_id', 'paypal_client_secret', 'paypal_sandbox',
			'klarna_api_uid', 'klarna_api_password', 'klarna_sandbox'],
		'invoicing' => ['invoice_prefix', 'seller_name', 'seller_email',
			'seller_address', 'seller_vat_id', 'seller_country', 'kleinunternehmer',
			'vies_online', 'oss_enabled', 'oss_vat_map',
			'datev_consultant_no', 'datev_client_no',
			'datev_revenue_account', 'datev_receivable_account'],
		'flows' => ['order_hold_min', 'approval_hold_min', 'payment_ttl_min',
			'otp_ttl_min', 'portal_sess_days', 'waitlist_offer_h'],
		'directory' => ['directory_enabled', 'cors_origins', 'default_locale',
			'ical_feed_token'],
		'general' => ['portal_enabled', 'license_key'],
		'integrations' => ['crm_sync_enabled', 'crm_sync_token',
			'av_enabled', 'av_icap_endpoint',
			'wallet_google_issuer', 'wallet_google_sa_email', 'wallet_google_secret',
			'wallet_apple_pass_type_id', 'wallet_apple_team_id',
			'wallet_apple_cert_pem', 'wallet_apple_key_pem'],
	];

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly SettingsService $settings,
		private readonly PspManager $psp,
		private readonly \OCA\BookingCheck\Db\CompanionTokenMapper $companions,
		private readonly \OCA\BookingCheck\Service\TokenService $tokens,
		private readonly \OCP\IUserSession $userSession,
		private readonly \OCA\BookingCheck\Service\SellerNotifyService $sellerNotify,
		private readonly \OCA\BookingCheck\Service\LicenseService $license,
		private readonly \OCA\BookingCheck\Db\OfferingMapper $offerings,
	) {
		parent::__construct($appName, $request);
	}

	/** List companion devices (token hashes never leave the server). */
	#[NoAdminRequired]
	public function devices(): JSONResponse
	{
		$rows = array_map(static function ($d) {
			$j = $d->jsonSerialize();
			unset($j['tokenHash']); // never surface the hash to the client
			return $j;
		}, $this->companions->findAll());
		return $this->ok(['devices' => $rows]);
	}

	/**
	 * Mint a door/manager device token. Plaintext returned exactly once —
	 * store it in the companion app now; it is not recoverable later.
	 */
	#[NoAdminRequired]
	public function issueDevice(): JSONResponse
	{
		$label = trim((string)$this->request->getParam('label', ''));
		$role = (string)$this->request->getParam('role', 'door');
		if ($label === '' || mb_strlen($label) > 190) {
			throw new \OCA\BookingCheck\Exception\ValidationException(['label' => 'Device name is required.']);
		}
		if (!in_array($role, ['door', 'manager', 'api'], true)) {
			throw new \OCA\BookingCheck\Exception\ValidationException(['role' => 'Role must be door, manager or api.']);
		}
		// scope: comma-separated offering ids — empty = all offerings.
		// A garbage scope would silently mean "no offerings" (fail-closed);
		// reject it loudly instead of minting a useless token.
		$scopeRaw = trim((string)$this->request->getParam('offering_ids', ''));
		if ($scopeRaw !== '') {
			$scopeIds = [];
			foreach (preg_split('/[\s,]+/', $scopeRaw) as $piece) {
				if (!preg_match('/^\d+$/', $piece)) {
					throw new \OCA\BookingCheck\Exception\ValidationException(
						['offering_ids' => 'Offering ids must be a comma-separated list of numbers.']);
				}
				$scopeIds[] = (int)$piece;
			}
			foreach ($scopeIds as $oid) {
				if ($this->offerings->find($oid) === null) {
					throw new \OCA\BookingCheck\Exception\ValidationException(
						['offering_ids' => 'Unknown offering in the scope list.']);
				}
			}
			// stored as JSON — CapiController::deviceScope() json_decodes it
			$scopeRaw = json_encode(array_values(array_unique($scopeIds)));
		}
		[$token, $hash] = $this->tokens->mint();
		$d = new \OCA\BookingCheck\Db\CompanionToken();
		$d->setTokenHash($hash);
		$d->setLabel($label);
		$d->setRole($role);
		$d->setOfferingIds($scopeRaw);
		$d->setCreatedBy($this->userSession->getUser()?->getUID() ?? 'admin');
		$d->setCreatedAt(new \DateTimeImmutable());
		$this->companions->insert($d);
		return $this->ok(['device' => [
			'id' => (int)$d->getId(), 'label' => $label, 'role' => $role,
			'token' => $token, // one-shot plaintext
		]], 201);
	}

	#[NoAdminRequired]
	public function revokeDevice(int $id): JSONResponse
	{
		$d = $this->companions->find($id);
		if ($d === null) {
			return $this->notFound();
		}
		$d->setRevokedAt(new \DateTimeImmutable());
		$this->companions->update($d);
		return $this->ok(['revoked' => true]);
	}

	#[NoAdminRequired]
	public function get(string $section): JSONResponse
	{
		if (!isset(self::SECTIONS[$section])) {
			return $this->notFound();
		}
		$out = [];
		foreach (self::SECTIONS[$section] as $k) {
			// secrets report only set/unset — plaintext never leaves the server
			$out[$k] = in_array($k, SettingsService::SECRET_KEYS, true)
				? ($this->settings->getSecret($k) !== '' ? '__set__' : '')
				: $this->settings->get($k);
		}
		return $this->ok(['settings' => $out]);
	}

	/** US-528 AC-2: per-user opt-out for paid-order notifications. */
	#[NoAdminRequired]
	public function setNotifyPaid(): JSONResponse
	{
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null || $uid === '') {
			throw new \OCA\BookingCheck\Exception\UnauthorizedException();
		}
		$on = in_array($this->request->getParam('enabled'), ['1', 'true', 'on', 1, true], true);
		$this->sellerNotify->setWantsPaid($uid, $on);
		return $this->ok(['enabled' => $on]);
	}

	#[NoAdminRequired]
	public function put(string $section): JSONResponse
	{
		if (!isset(self::SECTIONS[$section])) {
			return $this->notFound();
		}
		$in = $this->request->getParams();
		foreach (self::SECTIONS[$section] as $k) {
			if (!array_key_exists($k, $in)) {
				continue;
			}
			$v = (string)$in[$k];
			if (in_array($k, SettingsService::SECRET_KEYS, true)) {
				if ($v !== '' && $v !== '__set__') {
					$this->settings->setSecret($k, $v);
				}
				continue;
			}
			if ($k === 'psp_provider' && !in_array($v, ['', 'mollie', 'stripe', 'sumup', 'paypal', 'klarna'], true)) {
				throw new ValidationException(['psp_provider' => 'Unknown provider.']);
			}
			if (in_array($k, ['kleinunternehmer', 'vies_online', 'directory_enabled',
				'crm_sync_enabled', 'portal_enabled', 'oss_enabled', 'av_enabled',
				'paypal_sandbox', 'klarna_sandbox'], true)) {
				$v = in_array($v, ['1', 'true'], true) ? '1' : '0';
			}
			if ($k === 'seller_country' && !preg_match('/^[A-Z]{2}$/', $v)) {
				throw new ValidationException(['seller_country' => 'ISO country code.']);
			}
			if (in_array($k, ['order_hold_min', 'approval_hold_min', 'payment_ttl_min',
				'otp_ttl_min', 'portal_sess_days', 'waitlist_offer_h'], true)) {
				if (!is_numeric($v) || (int)$v < 1 || (int)$v > 525600) {
					throw new ValidationException([$k => 'Must be a positive duration.']);
				}
				$v = (string)(int)$v;
			}
			if ($k === 'oss_vat_map' && trim($v) !== '') {
				$map = json_decode($v, true);
				if (!is_array($map)) {
					throw new ValidationException(['oss_vat_map' => 'JSON map of country code → VAT basis points.']);
				}
				foreach ($map as $cc => $bp) {
					if (!preg_match('/^[A-Z]{2}$/', (string)$cc) || !is_numeric($bp)
						|| (int)$bp < 0 || (int)$bp > 10000) {
						throw new ValidationException(['oss_vat_map' => 'Keys are ISO country codes; values 0–10000 bp.']);
					}
				}
			}
			if ($k === 'ical_feed_token' && $v !== '' && !preg_match('/^[A-Za-z0-9_-]{16,64}$/', $v)) {
				throw new ValidationException(['ical_feed_token' => 'Token must be 16–64 url-safe chars.']);
			}
			if ($k === 'av_icap_endpoint' && trim($v) !== ''
				&& !preg_match('/^[A-Za-z0-9.\-]+:\\d{1,5}$/', trim($v))) {
				throw new ValidationException(['av_icap_endpoint' => 'host:port expected.']);
			}
			if ($k === 'cors_origins' && trim($v) !== '') {
				foreach (preg_split('/[\s,]+/', trim($v)) as $origin) {
					if ($origin === '*') {
						throw new ValidationException(['cors_origins' => 'Wildcard origins are not allowed.']);
					}
					$u = parse_url($origin);
					if (empty($u['host']) || !in_array($u['scheme'] ?? '', ['https', 'http'], true)
						|| (($u['scheme'] === 'http') && !in_array($u['host'], ['localhost', '127.0.0.1'], true))) {
						throw new ValidationException(['cors_origins' => 'Only https:// origins (or http://localhost) are allowed.']);
					}
				}
			}
			$this->settings->set($k, $v);
		}
		$result = ['saved' => true];
		if ($section === 'payments' && $this->settings->get('psp_provider') !== '') {
			$result['psp_ok'] = $this->psp->forCheckout()->testConnection();
		}
		if ($section === 'general' && array_key_exists('license_key', $in)) {
			// echo the LIVE verdict back — a typoed key must surface at save
			$result['license'] = $this->license->status();
		}
		return $this->ok($result);
	}
}

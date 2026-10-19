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
			'stripe_secret_key', 'stripe_webhook_secret', 'mollie_webhook_secret'],
		'invoicing' => ['invoice_prefix', 'seller_name', 'seller_email',
			'seller_address', 'seller_vat_id', 'seller_country', 'kleinunternehmer'],
		'flows' => ['order_hold_min', 'approval_hold_min', 'payment_ttl_min',
			'otp_ttl_min', 'portal_sess_days', 'waitlist_offer_h'],
		'directory' => ['directory_enabled', 'cors_origins', 'default_locale'],
		'general' => ['portal_enabled', 'license_key'],
	];

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly SettingsService $settings,
		private readonly PspManager $psp,
		private readonly \OCA\BookingCheck\Db\CompanionTokenMapper $companions,
		private readonly \OCA\BookingCheck\Service\TokenService $tokens,
		private readonly \OCP\IUserSession $userSession,
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
		if (!in_array($role, ['door', 'manager'], true)) {
			throw new \OCA\BookingCheck\Exception\ValidationException(['role' => 'Role must be door or manager.']);
		}
		[$token, $hash] = $this->tokens->mint();
		$d = new \OCA\BookingCheck\Db\CompanionToken();
		$d->setTokenHash($hash);
		$d->setLabel($label);
		$d->setRole($role);
		$d->setOfferingIds((string)$this->request->getParam('offering_ids', ''));
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
			if ($k === 'psp_provider' && !in_array($v, ['', 'mollie', 'stripe'], true)) {
				throw new ValidationException(['psp_provider' => 'Unknown provider.']);
			}
			if ($k === 'kleinunternehmer') {
				$v = in_array($v, ['1', 'true'], true) ? '1' : '0';
			}
			if ($k === 'seller_country' && !preg_match('/^[A-Z]{2}$/', $v)) {
				throw new ValidationException(['seller_country' => 'ISO country code.']);
			}
			$this->settings->set($k, $v);
		}
		$result = ['saved' => true];
		if ($section === 'payments' && $this->settings->get('psp_provider') !== '') {
			$result['psp_ok'] = $this->psp->forCheckout()->testConnection();
		}
		return $this->ok($result);
	}
}

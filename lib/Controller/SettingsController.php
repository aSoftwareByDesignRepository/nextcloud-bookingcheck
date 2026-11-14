<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Exception\ValidationException;
use OCA\BookingCheck\Psp\PspManager;
use OCA\BookingCheck\Service\AccessControlService;
use OCA\BookingCheck\Service\DirectoryOptionsService;
use OCA\BookingCheck\Service\MailRenderer;
use OCA\BookingCheck\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;

/** Admin settings JSON — secrets only ever written, never read back. */
class SettingsController extends Controller
{
	use ApiControllerTrait;

	private const ACCESS_LIST_KEYS = [
		AccessControlService::KEY_APP_ADMIN_USER_IDS => 'access_app_admin_user_ids',
		AccessControlService::KEY_ALLOWED_USER_IDS => 'access_allowed_user_ids',
		AccessControlService::KEY_ALLOWED_GROUP_IDS => 'access_allowed_group_ids',
	];

	private const SECTIONS = [
		'access' => [
			AccessControlService::KEY_RESTRICTION_ENABLED,
			AccessControlService::KEY_APP_ADMIN_USER_IDS,
			AccessControlService::KEY_ALLOWED_USER_IDS,
			AccessControlService::KEY_ALLOWED_GROUP_IDS,
		],
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
		private readonly \OCA\BookingCheck\Db\MailTplMapper $mailTpls,
		private readonly AccessControlService $accessControl,
		private readonly DirectoryOptionsService $directory,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Instance configuration is app-admin territory (family principle 12):
	 * the route middleware admits every allowlisted organizer, but only app
	 * administrators may read or write these settings.
	 */
	private function requireConfigAdmin(): string
	{
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		if ($uid === '') {
			throw new \OCA\BookingCheck\Exception\UnauthorizedException();
		}
		if (!$this->accessControl->isAppAdmin($uid)) {
			throw new \OCA\BookingCheck\Exception\UnauthorizedException('requires BookingCheck admin role');
		}
		return $uid;
	}

	/** List companion devices (token hashes never leave the server). */
	#[NoAdminRequired]
	public function devices(): JSONResponse
	{
		$this->requireConfigAdmin();
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
		$this->requireConfigAdmin();
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
			foreach (preg_split('/[\s,]+/', $scopeRaw) ?: [] as $piece) {
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
			$scopeRaw = json_encode(array_values(array_unique($scopeIds))) ?: '[]';
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
		$this->requireConfigAdmin();
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
		$this->requireConfigAdmin();
		if (!isset(self::SECTIONS[$section])) {
			return $this->notFound();
		}
		$out = [];
		foreach (self::SECTIONS[$section] as $k) {
			if (isset(self::ACCESS_LIST_KEYS[$k])) {
				// door lists surface decoded — clients never re-parse JSON
				$out[$k] = $this->accessListValues($k);
				continue;
			}
			// secrets report only set/unset — plaintext never leaves the server
			$out[$k] = in_array($k, SettingsService::SECRET_KEYS, true)
				? ($this->settings->getSecret($k) !== '' ? '__set__' : '')
				: $this->settings->get($k);
		}
		return $this->ok(['settings' => $out]);
	}

	/**
	 * Directory search for the access pickers — app admins only, min 2
	 * chars, uid+display-name merge happens in DirectoryOptionsService.
	 */
	#[NoAdminRequired]
	public function searchUsers(string $q = ''): JSONResponse
	{
		$this->requireConfigAdmin();
		$query = trim($q !== '' ? $q : (string)$this->request->getParam('q', ''));
		if (mb_strlen($query) < 2) {
			return $this->ok(['users' => []]);
		}
		return $this->ok(['users' => $this->directory->searchUsers($query, 20)]);
	}

	/** NH-37: list seller mail-template overrides (kind+locale → subject/extra). */
	#[NoAdminRequired]
	public function mailTplList(): JSONResponse
	{
		$this->requireConfigAdmin();
		return $this->ok(['templates' => array_map(
			static fn (\OCA\BookingCheck\Db\MailTpl $t) => [
				'id' => (int)$t->getId(),
				'kind' => (string)$t->getKind(),
				'locale' => (string)$t->getLocale(),
				'subject' => (string)($t->getSubject() ?? ''),
				'extra_text' => (string)($t->getExtraText() ?? ''),
			],
			$this->mailTpls->listAll(),
		), 'kinds' => MailRenderer::KINDS]);
	}

	/** NH-37: upsert one override — empty subject+extra deletes the row. */
	#[NoAdminRequired]
	public function mailTplPut(): JSONResponse
	{
		$this->requireConfigAdmin();
		$in = $this->request->getParams();
		$kind = (string)($in['kind'] ?? '');
		if (!in_array($kind, MailRenderer::KINDS, true)) {
			throw new ValidationException(['kind' => 'Unknown mail kind.']);
		}
		$locale = trim((string)($in['locale'] ?? ''));
		if ($locale !== '' && !preg_match('/^[a-z]{2}([_-][A-Za-z]{2})?$/', $locale)) {
			throw new ValidationException(['locale' => 'Language must be a code like en or de, or empty for all languages.']);
		}
		$subject = mb_substr(trim((string)($in['subject'] ?? '')), 0, 190);
		$extra = mb_substr(trim((string)($in['extra_text'] ?? '')), 0, 4000);
		if ($subject === '' && $extra === '') {
			$existing = $this->mailTpls->findFor($kind, $locale);
			if ($existing !== null) {
				$this->mailTpls->delete($existing);
			}
			return $this->ok(['deleted' => true]);
		}
		$tpl = $this->mailTpls->upsert($kind, $locale, $subject, $extra);
		return $this->ok(['template' => [
			'id' => (int)$tpl->getId(), 'kind' => $kind, 'locale' => $locale,
			'subject' => $subject, 'extra_text' => $extra,
		]]);
	}

	/** NH-37: remove an override — the built-in template applies again. */
	#[NoAdminRequired]
	public function mailTplDelete(int $id): JSONResponse
	{
		$this->requireConfigAdmin();
		$tpl = $this->mailTpls->find($id);
		if ($tpl === null) {
			return $this->notFound();
		}
		$this->mailTpls->delete($tpl);
		return $this->ok(['deleted' => true]);
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

	/**
	 * Dedicated license clear — the shared license page calls this on its own
	 * route (settings#put only writes present keys, so "clear" cannot ride
	 * the section PUT without changing that contract).
	 */
	#[NoAdminRequired]
	public function clearLicense(): JSONResponse
	{
		$this->requireConfigAdmin();
		$this->settings->set('license_key', '');
		return $this->ok(['license' => $this->license->status()]);
	}

	#[NoAdminRequired]
	public function put(string $section): JSONResponse
	{
		$this->requireConfigAdmin();
		if (!isset(self::SECTIONS[$section])) {
			return $this->notFound();
		}
		$in = $this->request->getParams();
		if ($section === 'access') {
			return $this->putAccess($in);
		}
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
			try {
				$v = $this->settings->normalizeForWrite($k, $v);
			} catch (\InvalidArgumentException $e) {
				throw new ValidationException([$k => $e->getMessage()]);
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

	/**
	 * Access-door write path — list fields carry JSON arrays, every entry
	 * must resolve against the live directory (ghost-UID/GID reject).
	 * @param array<string,mixed> $in
	 */
	private function putAccess(array $in): JSONResponse
	{
		if (array_key_exists(AccessControlService::KEY_RESTRICTION_ENABLED, $in)) {
			$this->settings->set(AccessControlService::KEY_RESTRICTION_ENABLED,
				$this->accessControl->normalizeForWrite(
					AccessControlService::KEY_RESTRICTION_ENABLED,
					(string)$in[AccessControlService::KEY_RESTRICTION_ENABLED],
				));
		}
		foreach (self::ACCESS_LIST_KEYS as $storeKey => $field) {
			if (!array_key_exists($storeKey, $in)) {
				continue;
			}
			try {
				$normalized = $this->accessControl->normalizeForWrite($storeKey, (string)$in[$storeKey]);
			} catch (\InvalidArgumentException|\JsonException) {
				throw new ValidationException([$field => 'Expected a JSON list of ids.']);
			}
			$this->settings->set($storeKey,
				json_encode($this->validatedDirectoryIds($normalized, $field)) ?: '[]');
		}
		return $this->ok(['saved' => true]);
	}

	/**
	 * Decode + verify a directory list. Unknown users/groups are a hard
	 * field error — a stored ghost id would sit silently on the door list
	 * forever (api-security-patterns §2).
	 *
	 * @return list<string>
	 */
	private function validatedDirectoryIds(string $raw, string $field): array
	{
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			throw new ValidationException([$field => 'Expected a JSON list of ids.']);
		}
		if (count($decoded) > 500) {
			throw new ValidationException([$field => 'Too many entries — keep the list under 500.']);
		}
		$isGroupList = $field === 'access_allowed_group_ids';
		$out = [];
		foreach ($decoded as $entry) {
			$id = trim((string)$entry);
			if ($id === '') {
				continue;
			}
			$exists = $isGroupList
				? $this->groupManager->get($id) !== null
				: (($u = $this->userManager->get($id)) !== null
					&& (!method_exists($u, 'isEnabled') || $u->isEnabled()));
			if (!$exists) {
				throw new ValidationException([$field => ($isGroupList
					? $this->tx('Unknown group')
					: $this->tx('Unknown user')) . ': ' . $id]);
			}
			$out[] = $id;
		}
		return array_values(array_unique($out));
	}

	/** @return list<string> */
	private function accessListValues(string $storeKey): array
	{
		return match ($storeKey) {
			AccessControlService::KEY_APP_ADMIN_USER_IDS => $this->accessControl->getAppAdminUserIds(),
			AccessControlService::KEY_ALLOWED_USER_IDS => $this->accessControl->getAllowedUserIds(),
			default => $this->accessControl->getAllowedGroupIds(),
		};
	}
}

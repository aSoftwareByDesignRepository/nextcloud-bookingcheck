<?php

declare(strict_types=1);

/**
 * BookingCheck wiring for the shared transfer engine.
 *
 *  - requireTransferAdmin gates on the app's real door-admin role
 *    (AccessControlService::isAppAdmin — system admins + configured app
 *    admins + the bookingcheck_admin group).
 *  - validateValue/normalizeValue delegate to the SAME normalisers the
 *    settings surfaces use — AccessControlService::normalizeForWrite for
 *    the access-door keys, SettingsService::normalizeForWrite for every
 *    other settings key (validator parity, NN-08).
 *  - *_enc secret values are ICrypto ciphertext: they only round-trip on
 *    an instance that can still decrypt them — a foreign ciphertext from
 *    an --include-secrets file is rejected instead of planted (fail
 *    closed; plaintext secrets never reach this code path anyway because
 *    the app never stores them).
 *  - Access-door policy lives in app-local appconfig (no platform
 *    restriction mirror) → there are NO mirrored keys; validateMirrored/
 *    writeMirrored fail loudly if a manifest ever declares one.
 *  - createSafetySnapshot delegates to UpgradeBackupService for the
 *    mandatory pre-replace snapshot (FS-2).
 */

namespace OCA\BookingCheck\ConfigTransfer;

use OCA\BookingCheck\Exception\UnauthorizedException;
use OCA\BookingCheck\Service\AccessControlService;
use OCA\BookingCheck\Service\AuditService;
use OCA\BookingCheck\Service\SettingsService;
use OCA\BookingCheck\Service\UpgradeBackupService;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

final class TransferHooks implements ConfigTransferHooks
{
	private const ACCESS_KEYS = [
		AccessControlService::KEY_RESTRICTION_ENABLED,
		AccessControlService::KEY_APP_ADMIN_USER_IDS,
		AccessControlService::KEY_ALLOWED_USER_IDS,
		AccessControlService::KEY_ALLOWED_GROUP_IDS,
	];

	public function __construct(
		private readonly SettingsService $settings,
		private readonly AccessControlService $access,
		private readonly UpgradeBackupService $backups,
		private readonly AuditService $audit,
		private readonly ICrypto $crypto,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}

	public function requireTransferAdmin(string $uid): void
	{
		if ($uid === '' || !$this->access->isAppAdmin($uid)) {
			throw new UnauthorizedException('requires BookingCheck admin role');
		}
	}

	public function validateValue(string $key, string $value): ?string
	{
		try {
			$this->normalize($key, $value);
			return null;
		} catch (\InvalidArgumentException $e) {
			return $e->getMessage() !== '' ? $e->getMessage() : 'INVALID_VALUE';
		} catch (\JsonException) {
			return 'INVALID_JSON';
		} catch (\Throwable) {
			return 'INVALID_VALUE';
		}
	}

	public function normalizeValue(string $key, string $value): string
	{
		try {
			return $this->normalize($key, $value);
		} catch (\Throwable) {
			return $value; // validateValue already rejected bad input
		}
	}

	private function normalize(string $key, string $value): string
	{
		if (in_array($key, self::ACCESS_KEYS, true)) {
			return $this->access->normalizeForWrite($key, $value);
		}
		if (str_ends_with($key, '_enc')) {
			// *_enc values are ICrypto ciphertext bound to the instance
			// secret. A ciphertext that does not decrypt HERE belongs to a
			// different instance — writing it would silently corrupt the
			// PSP/wallet credential it shadows.
			try {
				$plain = $this->crypto->decrypt($value);
			} catch (\Throwable) {
				$plain = false;
			}
			if (!is_string($plain)) {
				throw new \InvalidArgumentException('FOREIGN_SECRET_CIPHERTEXT');
			}
			return $value;
		}
		try {
			return $this->settings->normalizeForWrite($key, $value);
		} catch (\InvalidArgumentException $e) {
			if ($e->getMessage() !== 'UNKNOWN_SETTING') {
				throw $e;
			}
			// Manifested but not a settings key — size guard only.
			return strlen($value) <= 65536
				? $value
				: throw new \InvalidArgumentException('TOO_LONG');
		}
	}

	public function validateMirrored(array $keyValues): ?string
	{
		return $keyValues === [] ? null : 'NO_MIRRORED_KEYS';
	}

	public function writeMirrored(array $keyValues): void
	{
		if ($keyValues !== []) {
			throw new \InvalidArgumentException('NO_MIRRORED_KEYS');
		}
	}

	public function securityRelevantKeys(): array
	{
		return [
			'access_restriction_enabled',
			'access_app_admin_user_ids',
			'access_allowed_user_ids',
			'access_allowed_group_ids',
			'psp_provider',
			'portal_enabled',
			'directory_enabled',
			'license_key',
		];
	}

	public function createSafetySnapshot(string $reason): ?string
	{
		try {
			$result = $this->backups->createSnapshot($reason);
			return (string)($result['id'] ?? '');
		} catch (\Throwable) {
			// No data to back up yet (fresh instance) — not an error, but no
			// snapshot id either; the apply must not be silently unprotected
			// when tables DO exist, so rethrow real failures on non-empty DBs.
			if ($this->backups->hasDataToBackup()) {
				throw new \RuntimeException('TRANSFER_SNAPSHOT_FAILED');
			}
			return null;
		}
	}

	public function settingsSectionForKey(string $key): ?string
	{
		return match (true) {
			str_starts_with($key, 'access_') => 'access',
			str_starts_with($key, 'psp_') || str_starts_with($key, 'mollie_')
				|| str_starts_with($key, 'stripe_') || str_starts_with($key, 'sumup_')
				|| str_starts_with($key, 'paypal_') || str_starts_with($key, 'klarna_') => 'payments',
			str_starts_with($key, 'crm_sync_') || str_starts_with($key, 'av_')
				|| str_starts_with($key, 'wallet_') => 'integrations',
			str_starts_with($key, 'license_') => 'license',
			str_starts_with($key, 'invoice_') || str_starts_with($key, 'seller_')
				|| str_starts_with($key, 'kleinunternehmer') || str_starts_with($key, 'vies_')
				|| str_starts_with($key, 'oss_') || str_starts_with($key, 'datev_') => 'invoicing',
			str_starts_with($key, 'order_hold') || str_starts_with($key, 'approval_hold')
				|| str_starts_with($key, 'payment_ttl') || str_starts_with($key, 'otp_')
				|| str_starts_with($key, 'portal_sess') || str_starts_with($key, 'waitlist_') => 'flow',
			str_starts_with($key, 'portal_') || str_starts_with($key, 'directory_')
				|| str_starts_with($key, 'default_locale') || str_starts_with($key, 'ical_')
				|| str_starts_with($key, 'cors_') => 'portal',
			default => null,
		};
	}

	public function auditEvent(string $action, string $actor, array $details): void
	{
		try {
			$this->audit->log(
				null,
				null,
				$actor === '' ? 'cli' : $actor,
				'config_transfer_' . $action,
				null,
				null,
				$details,
			);
		} catch (\Throwable $e) {
			// audit must never fail the transfer itself
			$this->logger->warning('bookingcheck: config transfer audit event failed', [
				'app' => 'bookingcheck',
				'action' => $action,
				'exception' => $e,
			]);
		}
	}
}

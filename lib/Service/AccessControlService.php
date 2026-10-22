<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\AppInfo\Application;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * BookingCheck access model — family principle 12 (access door + App Admin
 * role), same shape as CustomerCheck/BudgetCheck.
 *
 *  1. **Dedicated App Admin role.** `isAppAdmin` = NC system admin OR
 *     uid ∈ `access_app_admin_user_ids` OR membership in the
 *     `bookingcheck_admin` group (the role's original carrier — kept so
 *     existing deployments and their group tooling keep working).
 *  2. **Access door.** Restricted mode (default): only app admins plus the
 *     `access_allowed_user_ids` / `access_allowed_group_ids` allowlists may
 *     open the organizer surface. Open mode: every logged-in user may.
 *  3. **Fail closed.** Empty allowlists under restriction mean nobody but
 *     app admins — identical to the pre-door fixed-group behaviour.
 *
 * Public guest surfaces (/pub, /psp, /capi, /scan) are exempt upstream in
 * AppAccessMiddleware and stay exempt here by construction: they never
 * reach this service.
 */
class AccessControlService
{
	public const ADMIN_GROUP = 'bookingcheck_admin';

	public const KEY_APP_ADMIN_USER_IDS = 'access_app_admin_user_ids';
	public const KEY_RESTRICTION_ENABLED = 'access_restriction_enabled';
	public const KEY_ALLOWED_USER_IDS = 'access_allowed_user_ids';
	public const KEY_ALLOWED_GROUP_IDS = 'access_allowed_group_ids';

	public const DENIAL_RESTRICTION = 'restriction';
	public const DENIAL_EMPTY = 'empty_user';

	public function __construct(
		private readonly IConfig $config,
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
	) {
	}

	/** Empty string when the request is anonymous — never throws. */
	public function currentUserIdOrEmpty(): string
	{
		$user = $this->userSession->getUser();

		return $user === null ? '' : $user->getUID();
	}

	public function isSystemAdmin(string $userId): bool
	{
		return $userId !== '' && $this->groupManager->isAdmin($userId);
	}

	/**
	 *     if uid == '': return false
	 *     if isSystemAdmin(uid): return true
	 *     return uid ∈ access_app_admin_user_ids OR uid ∈ bookingcheck_admin
	 */
	public function isAppAdmin(string $userId): bool
	{
		if ($userId === '') {
			return false;
		}
		if ($this->isSystemAdmin($userId)) {
			return true;
		}
		if ($this->groupManager->isInGroup($userId, self::ADMIN_GROUP)) {
			return true;
		}

		return in_array($userId, $this->getAppAdminUserIds(), true);
	}

	/**
	 * The directory door — who may open the organizer surface.
	 *
	 *     if uid == '': return false
	 *     if isAppAdmin(uid): return true
	 *     if not isAccessRestrictionEnabled(): return true   // Open mode
	 *     return userMatchesAccessAllowList(uid)             // Restricted
	 */
	public function canUseApp(string $userId): bool
	{
		if ($userId === '') {
			return false;
		}
		if ($this->isAppAdmin($userId)) {
			return true;
		}
		if (!$this->isAccessRestrictionEnabled()) {
			return true;
		}

		return $this->userMatchesAccessAllowList($userId);
	}

	public function canManageAppConfiguration(string $userId): bool
	{
		return $this->isAppAdmin($userId);
	}

	/**
	 * Defaults to ON: the door's pre-history access set (sysadmins +
	 * bookingcheck_admin members only) survives an upgrade bit-for-bit.
	 */
	public function isAccessRestrictionEnabled(): bool
	{
		return $this->config->getAppValue(
			Application::APP_ID,
			self::KEY_RESTRICTION_ENABLED,
			'1',
		) === '1';
	}

	/**
	 * Restriction on + both allowlists empty ⇒ only app admins get in.
	 * The settings UI renders a danger callout for this state.
	 */
	public function isFailClosedWithEmptyAllowLists(): bool
	{
		return $this->isAccessRestrictionEnabled()
			&& $this->getAllowedUserIds() === []
			&& $this->getAllowedGroupIds() === [];
	}

	public function userMatchesAccessAllowList(string $userId): bool
	{
		if ($userId === '') {
			return false;
		}
		if (in_array($userId, $this->getAllowedUserIds(), true)) {
			return true;
		}
		foreach ($this->getAllowedGroupIds() as $groupId) {
			if ($this->groupManager->isInGroup($userId, $groupId)) {
				return true;
			}
		}

		return false;
	}

	/** @return list<string> */
	public function getAppAdminUserIds(): array
	{
		return $this->getJsonList(self::KEY_APP_ADMIN_USER_IDS);
	}

	/** @return list<string> */
	public function getAllowedUserIds(): array
	{
		return $this->getJsonList(self::KEY_ALLOWED_USER_IDS);
	}

	/** @return list<string> */
	public function getAllowedGroupIds(): array
	{
		return $this->getJsonList(self::KEY_ALLOWED_GROUP_IDS);
	}

	/** @return list<string> */
	private function getJsonList(string $key): array
	{
		$raw = $this->config->getAppValue(Application::APP_ID, $key, '[]');
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}

		$values = [];
		foreach ($decoded as $entry) {
			if (is_string($entry) || is_int($entry)) {
				$value = trim((string)$entry);
				if ($value !== '') {
					$values[] = $value;
				}
			}
		}

		return array_values(array_unique($values));
	}
}

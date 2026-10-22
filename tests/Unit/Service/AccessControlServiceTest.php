<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Tests\Unit\Service;

use OCA\BookingCheck\AppInfo\Application;
use OCA\BookingCheck\Service\AccessControlService;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The access-door truth table, one row per test:
 *
 *   uid=''            → nothing, ever
 *   system admin      → app admin (always)
 *   bookingcheck_admin→ app admin (legacy carrier)
 *   KEY_APP_ADMIN ids → app admin (delegated, no NC sysadmin needed)
 *   restriction off   → every logged-in uid may use the app (Open mode)
 *   restriction on    → only allowlisted users / group members (+admins)
 *   restriction on + empty lists → fail closed to app admins only
 *
 * The default for a missing restriction flag is '1' (restricted) so an
 * upgrade keeps the pre-door access set bit-for-bit.
 */
class AccessControlServiceTest extends TestCase
{
	private IConfig&MockObject $config;
	private IGroupManager&MockObject $groups;
	private IUserSession&MockObject $session;
	private AccessControlService $svc;

	/** @var array<string,string> */
	private array $appValues = [];

	protected function setUp(): void
	{
		parent::setUp();
		$this->appValues = [];
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => $this->appValues[$key] ?? $default);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->svc = new AccessControlService(
			$this->config, $this->groups, $this->session);
	}

	public function testAnonymousGetsNothing(): void
	{
		$this->appValues[AccessControlService::KEY_RESTRICTION_ENABLED] = '0'; // even Open
		self::assertFalse($this->svc->isAppAdmin(''));
		self::assertFalse($this->svc->canUseApp(''));
	}

	public function testSystemAdminIsAppAdmin(): void
	{
		$this->groups->method('isAdmin')->with('root')->willReturn(true);
		self::assertTrue($this->svc->isAppAdmin('root'));
		self::assertTrue($this->svc->canManageAppConfiguration('root'));
		// even under restriction with empty lists
		$this->appValues[AccessControlService::KEY_RESTRICTION_ENABLED] = '1';
		self::assertTrue($this->svc->canUseApp('root'));
	}

	public function testLegacyGroupMemberIsAppAdmin(): void
	{
		$this->groups->method('isAdmin')->willReturn(false);
		$this->groups->method('isInGroup')->willReturnCallback(
			fn (string $uid, string $gid) => $gid === AccessControlService::ADMIN_GROUP && $uid === 'seller');
		self::assertTrue($this->svc->isAppAdmin('seller'));
		self::assertTrue($this->svc->canUseApp('seller'));
		self::assertFalse($this->svc->isAppAdmin('bob'));
	}

	public function testConfiguredUidIsAppAdminWithoutNcAdmin(): void
	{
		$this->groups->method('isAdmin')->willReturn(false);
		$this->groups->method('isInGroup')->willReturn(false);
		$this->appValues[AccessControlService::KEY_APP_ADMIN_USER_IDS] = '["delegated","second"]';
		self::assertTrue($this->svc->isAppAdmin('delegated'));
		self::assertTrue($this->svc->isAppAdmin('second'));
		self::assertFalse($this->svc->isAppAdmin('third'));
	}

	public function testOpenModeLetsEveryLoggedInUserIn(): void
	{
		$this->groups->method('isAdmin')->willReturn(false);
		$this->groups->method('isInGroup')->willReturn(false);
		$this->appValues[AccessControlService::KEY_RESTRICTION_ENABLED] = '0';
		self::assertTrue($this->svc->canUseApp('anybody'));
		// …but Open mode does NOT mint app admins
		self::assertFalse($this->svc->isAppAdmin('anybody'));
		self::assertFalse($this->svc->canManageAppConfiguration('anybody'));
	}

	public function testRestrictedModeAllowsListedUser(): void
	{
		$this->groups->method('isAdmin')->willReturn(false);
		$this->groups->method('isInGroup')->willReturn(false);
		$this->appValues[AccessControlService::KEY_RESTRICTION_ENABLED] = '1';
		$this->appValues[AccessControlService::KEY_ALLOWED_USER_IDS] = '["organizer"]';
		self::assertTrue($this->svc->canUseApp('organizer'));
		self::assertFalse($this->svc->isAppAdmin('organizer')); // user, not admin
		self::assertFalse($this->svc->canUseApp('intruder'));
	}

	public function testRestrictedModeAllowsListedGroup(): void
	{
		$this->groups->method('isAdmin')->willReturn(false);
		$this->groups->method('isInGroup')->willReturnCallback(
			fn (string $uid, string $gid) => $gid === 'sales' && $uid === 'member1');
		$this->appValues[AccessControlService::KEY_RESTRICTION_ENABLED] = '1';
		$this->appValues[AccessControlService::KEY_ALLOWED_GROUP_IDS] = '["sales"]';
		self::assertTrue($this->svc->canUseApp('member1'));
		self::assertFalse($this->svc->canUseApp('member2'));
	}

	public function testRestrictedEmptyListsFailClosed(): void
	{
		$this->groups->method('isAdmin')->willReturn(false);
		$this->groups->method('isInGroup')->willReturn(false);
		$this->appValues[AccessControlService::KEY_RESTRICTION_ENABLED] = '1';
		// both allowlists unset → nobody but app admins
		self::assertFalse($this->svc->canUseApp('bob'));
		self::assertTrue($this->svc->isFailClosedWithEmptyAllowLists());
	}

	public function testMissingRestrictionFlagDefaultsToRestricted(): void
	{
		// no KEY_RESTRICTION_ENABLED at all → '1'
		self::assertTrue($this->svc->isAccessRestrictionEnabled());
		$this->groups->method('isAdmin')->willReturn(false);
		$this->groups->method('isInGroup')->willReturn(false);
		self::assertFalse($this->svc->canUseApp('bob'));
	}

	public function testMalformedJsonListsAreEmptyNotCrash(): void
	{
		$this->groups->method('isAdmin')->willReturn(false);
		$this->groups->method('isInGroup')->willReturn(false);
		$this->appValues[AccessControlService::KEY_RESTRICTION_ENABLED] = '1';
		$this->appValues[AccessControlService::KEY_ALLOWED_USER_IDS] = '{not-json';
		$this->appValues[AccessControlService::KEY_ALLOWED_GROUP_IDS] = '42';
		self::assertSame([], $this->svc->getAllowedUserIds());
		self::assertSame([], $this->svc->getAllowedGroupIds());
		self::assertFalse($this->svc->canUseApp('bob'));
	}

	public function testListNormalizationDedupesAndCoercesScalars(): void
	{
		// ints survive as stringified uids (a uid CAN be numeric); bools,
		// nulls and nested arrays are dropped; empties and dupes collapse
		$this->appValues[AccessControlService::KEY_ALLOWED_USER_IDS] = '["a","a","","b",7,true,null,["x"]]';
		self::assertSame(['a', 'b', '7'], $this->svc->getAllowedUserIds());
	}

	public function testCurrentUserIdOrEmpty(): void
	{
		$this->session->method('getUser')->willReturn(null);
		self::assertSame('', $this->svc->currentUserIdOrEmpty());

		$session2 = $this->createMock(IUserSession::class);
		$u = $this->createMock(IUser::class);
		$u->method('getUID')->willReturn('alice');
		$session2->method('getUser')->willReturn($u);
		$svc2 = new AccessControlService($this->config, $this->groups, $session2);
		self::assertSame('alice', $svc2->currentUserIdOrEmpty());
	}

	public function testAppAdminListIsSeparateFromAllowedList(): void
	{
		// A user on the USE allowlist is not thereby an app admin — the two
		// grants are deliberately separate (spec: delegation ≠ usage).
		$this->groups->method('isAdmin')->willReturn(false);
		$this->groups->method('isInGroup')->willReturn(false);
		$this->appValues[AccessControlService::KEY_ALLOWED_USER_IDS] = '["user1"]';
		$this->appValues[AccessControlService::KEY_APP_ADMIN_USER_IDS] = '["admin1"]';
		self::assertFalse($this->svc->isAppAdmin('user1'));
		self::assertTrue($this->svc->isAppAdmin('admin1'));
		// and the app-admin uid does not need the use-allowlist entry
		$this->appValues[AccessControlService::KEY_RESTRICTION_ENABLED] = '1';
		self::assertTrue($this->svc->canUseApp('admin1'));
	}
}

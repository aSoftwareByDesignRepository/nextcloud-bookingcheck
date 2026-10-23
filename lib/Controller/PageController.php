<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Service\AccessControlService;
use OCA\BookingCheck\Service\DirectoryOptionsService;
use OCA\BookingCheck\Service\SettingsViewData;
use OCA\BookingCheck\Support\NavGate;
use OCA\BookingCheck\Support\SettingsCatalog;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

class PageController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AccessControlService $accessControl,
		private readonly SettingsViewData $settingsViewData,
		private readonly DirectoryOptionsService $directory,
		private readonly IURLGenerator $urlGenerator,
	) {
		parent::__construct($appName, $request);
	}

	/** App root → canonical overview render (dashboard#index supplies
	    stats + recentOrders; a second render path without them shipped a
	    permanently-zero stat strip). */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): RedirectResponse
	{
		return new RedirectResponse($this->urlGenerator->linkToRoute(
			'bookingcheck.dashboard.index'));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function needsRole(): TemplateResponse
	{
		// Denied surface must fail closed at HTTP level — scanners and
		// CDN edges see the 403, browsers still get the friendly page.
		$resp = new TemplateResponse($this->appName, 'admin/needs-role', []);
		$resp->setStatus(Http::STATUS_FORBIDDEN);
		return $resp;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function supportUs(): TemplateResponse
	{
		return new TemplateResponse($this->appName, 'support-us', []);
	}

	/** Upsell landing for Pro-gated page navigations (LicenseGate). */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function pro(): TemplateResponse
	{
		return new TemplateResponse($this->appName, 'admin/pro', [
			'navId' => 'pro',
		]);
	}

	/** /settings → first catalog section. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settings(): RedirectResponse
	{
		return new RedirectResponse($this->urlGenerator->linkToRoute(
			'bookingcheck.page.settingsSection',
			['section' => SettingsCatalog::SECTIONS[0]],
		));
	}

	/**
	 * One page per topic (family settings-pages standard). Instance
	 * configuration is app-admin territory — an allowlisted organizer gets
	 * the friendly needs-role page, never a form that can't save.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settingsSection(string $section): TemplateResponse|NotFoundResponse
	{
		$section = strtolower(trim($section));
		if (!SettingsCatalog::isSection($section)) {
			return new NotFoundResponse();
		}
		$uid = $this->accessControl->currentUserIdOrEmpty();
		if (!$this->accessControl->isAppAdmin($uid)) {
			$resp = new TemplateResponse($this->appName, 'admin/needs-role', [
				'needsAdmin' => true,
			]);
			$resp->setStatus(Http::STATUS_FORBIDDEN);
			return $resp;
		}

		$params = $this->settingsViewData->get();
		$params['settingsSection'] = $section;
		if ($section === 'access') {
			$params += $this->accessViewData();
		}

		return new TemplateResponse($this->appName, 'settings', $params);
	}

	/** @return array<string,mixed> */
	private function accessViewData(): array
	{
		$selectedGids = $this->accessControl->getAllowedGroupIds();

		return [
			'accessRestrictionOn' => $this->accessControl->isAccessRestrictionEnabled(),
			'accessFailClosed' => $this->accessControl->isFailClosedWithEmptyAllowLists(),
			'accessAppAdmins' => $this->directory->resolveUsers($this->accessControl->getAppAdminUserIds()),
			'accessAllowedUsers' => $this->directory->resolveUsers($this->accessControl->getAllowedUserIds()),
			'accessGroups' => $this->directory->availableGroups(),
			'accessSelectedGroupIds' => $selectedGids,
		];
	}
}

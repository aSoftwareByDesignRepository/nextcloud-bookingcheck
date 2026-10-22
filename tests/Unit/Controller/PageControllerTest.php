<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit\Controller;

use OCA\BookingCheck\Controller\PageController;
use OCA\BookingCheck\Service\AccessControlService;
use OCA\BookingCheck\Service\DirectoryOptionsService;
use OCA\BookingCheck\Service\SettingsViewData;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/** deny_page_status_200 — an authorization failure on an HTML/page route
 *  must fail closed at HTTP level (403) while still rendering the
 *  friendly deny template; a 200 with "no access" content reads as
 *  success to status scanners and CDN edges. */
final class PageControllerTest extends TestCase
{
	private AccessControlService&MockObject $accessControl;
	private PageController $ctrl;

	protected function setUp(): void
	{
		parent::setUp();
		$this->accessControl = $this->createMock(AccessControlService::class);
		$this->ctrl = new PageController(
			'bookingcheck',
			$this->createMock(IRequest::class),
			$this->accessControl,
			$this->createMock(SettingsViewData::class),
			$this->createMock(DirectoryOptionsService::class),
			$this->createMock(IURLGenerator::class),
		);
	}

	public function testNeedsRoleAnswers403WithDenyTemplate(): void
	{
		$resp = $this->ctrl->needsRole();
		self::assertInstanceOf(TemplateResponse::class, $resp);
		self::assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
	}

	/** A member allowed into the app but NOT app-admin is denied the
	 *  settings section — same 403, same friendly template. */
	public function testSettingsSectionNonAdminAnswers403(): void
	{
		$this->accessControl->method('currentUserIdOrEmpty')->willReturn('member1');
		$this->accessControl->method('isAppAdmin')->willReturn(false);
		$resp = $this->ctrl->settingsSection('payments');
		self::assertInstanceOf(TemplateResponse::class, $resp);
		self::assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
	}
}

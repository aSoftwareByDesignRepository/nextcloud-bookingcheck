<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Tests\Unit\Controller;

use OCA\BookingCheck\Controller\FeedController;
use OCA\BookingCheck\Db\Offering;
use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Service\SettingsService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * Directory feed — opt-in only, no PII, and CORS reflects the REQUESTER's
 * allowlisted origin (never the first configured one unconditionally).
 */
final class FeedControllerTest extends TestCase
{
	private IRequest&MockObject $request;
	private OfferingMapper&MockObject $offerings;
	private SettingsService&MockObject $settings;
	private FeedController $ctrl;
	private string $requestOrigin = '';
	private string $cors = '';

	protected function setUp(): void
	{
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getHeader')->willReturnCallback(
			fn (string $name): string => $name === 'Origin' ? $this->requestOrigin : '');
		$this->offerings = $this->createMock(OfferingMapper::class);
		$this->offerings->method('findPublished')->willReturn([]);
		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('getBool')->willReturnCallback(
			fn (string $k): bool => $k === 'directory_enabled');
		$this->settings->method('get')->willReturnCallback(
			fn (string $k, ?string $d = null): string => $k === 'cors_origins' ? $this->cors : (string)$d);
		$this->ctrl = new FeedController('bookingcheck', $this->request, $this->offerings, $this->settings);
	}

	/** @return array<string,string> */
	private function headersOf(\OCP\AppFramework\Http\JSONResponse $r): array
	{
		$m = new \ReflectionMethod($r, 'getHeaders');
		return $m->invoke($r);
	}

	public function testDisabledDirectoryIsUniform404(): void
	{
		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('getBool')->willReturn(false);
		$ctrl = new FeedController('bookingcheck', $this->request, $this->offerings, $this->settings);
		$resp = $ctrl->directory();
		self::assertSame(404, $resp->getStatus());
		self::assertSame('NOT_FOUND', $resp->getData()['error']);
	}

	public function testAllowlistedOriginIsReflected(): void
	{
		$this->cors = 'https://a.example, https://b.example';
		$this->requestOrigin = 'https://b.example';
		$h = $this->headersOf($this->ctrl->directory());
		self::assertSame('https://b.example', $h['Access-Control-Allow-Origin'] ?? null,
			'second allowlisted origin must be reflectable — first-only breaks it');
		self::assertSame('Origin', $h['Vary'] ?? null);
	}

	public function testUnlistedOriginGetsNoHeader(): void
	{
		$this->cors = 'https://a.example';
		$this->requestOrigin = 'https://evil.example';
		$h = $this->headersOf($this->ctrl->directory());
		self::assertArrayNotHasKey('Access-Control-Allow-Origin', $h,
			'an unlisted origin must not receive the allowlist value either');
	}

	public function testNoOriginHeaderMeansNoCors(): void
	{
		$this->cors = 'https://a.example';
		$this->requestOrigin = '';
		$h = $this->headersOf($this->ctrl->directory());
		self::assertArrayNotHasKey('Access-Control-Allow-Origin', $h,
			'non-browser callers need no ACAO — and must not learn the allowlist');
	}

	public function testWildcardAndHttpOriginsAreIgnored(): void
	{
		$this->cors = '*, http://insecure.example, https://ok.example';
		$this->requestOrigin = 'http://insecure.example';
		$h = $this->headersOf($this->ctrl->directory());
		self::assertArrayNotHasKey('Access-Control-Allow-Origin', $h);
		$this->requestOrigin = 'https://ok.example';
		$h = $this->headersOf($this->ctrl->directory());
		self::assertSame('https://ok.example', $h['Access-Control-Allow-Origin'] ?? null);
	}
}

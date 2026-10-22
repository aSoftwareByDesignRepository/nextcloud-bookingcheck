<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Tests\Unit\Service;

use OCA\BookingCheck\Db\WebhookDeliveryMapper;
use OCA\BookingCheck\Db\WebhookMapper;
use OCA\BookingCheck\Exception\ValidationException;
use OCA\BookingCheck\Service\LicenseService;
use OCA\BookingCheck\Service\OutboundWebhookService;
use OCP\Http\Client\IClientService;
use OCP\IDBConnection;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * The SSRF gate is the only thing between an admin-entered URL and the
 * internal network — every internal range must refuse, v4 AND v6.
 * Deterministic inputs only: literals and /etc/hosts, never public DNS.
 */
final class OutboundWebhookServiceTest extends TestCase
{
	private OutboundWebhookService $svc;

	protected function setUp(): void
	{
		parent::setUp();
		$this->svc = new OutboundWebhookService(
			$this->createMock(WebhookMapper::class),
			$this->createMock(WebhookDeliveryMapper::class),
			$this->createMock(IClientService::class),
			$this->createMock(ICrypto::class),
			$this->createMock(LicenseService::class),
			$this->createMock(IDBConnection::class),
		);
	}

	private function assertRefused(string $url): void
	{
		try {
			$this->svc->assertUrlAllowed($url);
			self::fail("$url must be refused");
		} catch (ValidationException $e) {
			self::assertNotEmpty($e->getFields());
		}
	}

	public function testSchemeMustBeHttp(): void
	{
		$this->assertRefused('ftp://example.com/x');
		$this->assertRefused('file:///etc/passwd');
		$this->assertRefused('gopher://8.8.8.8/');
	}

	public function testPrivateIpv4LiteralsRefused(): void
	{
		$this->assertRefused('http://127.0.0.1/hook');
		$this->assertRefused('http://10.0.0.5/hook');
		$this->assertRefused('http://192.168.1.10/hook');
		$this->assertRefused('http://169.254.169.254/latest/meta-data'); // cloud metadata
		$this->assertRefused('http://0.0.0.0/hook');
	}

	public function testPrivateIpv6LiteralsRefused(): void
	{
		// bracketed literals — the hole gethostbynamel() could not see
		$this->assertRefused('http://[::1]/hook');               // loopback
		$this->assertRefused('http://[fe80::1]/hook');           // link-local
		$this->assertRefused('http://[fc00::1]/hook');           // ULA
		$this->assertRefused('http://[::ffff:127.0.0.1]/hook');  // v4-mapped loopback
	}

	public function testLocalhostResolvesPrivateAndRefuses(): void
	{
		// resolves via /etc/hosts — no external DNS needed
		$this->assertRefused('http://localhost/hook');
	}

	public function testPublicLiteralsAllowed(): void
	{
		$this->svc->assertUrlAllowed('https://8.8.8.8/hook');
		$this->svc->assertUrlAllowed('https://1.1.1.1/hook');
		$this->svc->assertUrlAllowed('https://[2001:4860:4860::8888]/hook');
		self::assertTrue(true);
	}
}

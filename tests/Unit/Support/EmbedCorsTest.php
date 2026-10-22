<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Tests\Unit\Support;

use OCA\BookingCheck\Support\EmbedCors;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * NH-05 — the single ACAO authority for the SDK embed: reflect iff the
 * requester Origin is in the offering's embed_domains allowlist.
 */
final class EmbedCorsTest extends TestCase
{
	private IRequest&MockObject $request;
	private string $origin = '';

	protected function setUp(): void
	{
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getHeader')->willReturnCallback(
			fn (string $n): string => $n === 'Origin' ? $this->origin : '');
	}

	/** @return array<string,string> */
	private function headersOf(JSONResponse $r): array
	{
		$m = new \ReflectionMethod($r, 'getHeaders');
		return $m->invoke($r);
	}

	private function resp(?string $domains = '["https://shop.example"]'): JSONResponse
	{
		return EmbedCors::apply(new JSONResponse(['ok' => true]), $this->request, $domains);
	}

	public function testExactAllowlistedOriginIsReflected(): void
	{
		$this->origin = 'https://shop.example';
		$h = $this->headersOf($this->resp());
		self::assertSame('https://shop.example', $h['Access-Control-Allow-Origin'] ?? null);
		self::assertSame('Origin', $h['Vary'] ?? null,
			'caches must not serve one origin\'s ACAO to another');
	}

	public function testForeignOriginGetsNoAcao(): void
	{
		$this->origin = 'https://evil.example';
		self::assertArrayNotHasKey('Access-Control-Allow-Origin', $this->headersOf($this->resp()));
	}

	public function testWildcardSuffixEntryCoversSubdomainsNotApex(): void
	{
		$domains = '["https://*.example.com"]';
		$this->origin = 'https://shop.example.com';
		self::assertSame('https://shop.example.com',
			$this->headersOf($this->resp($domains))['Access-Control-Allow-Origin'] ?? null);
		$this->origin = 'https://example.com';
		self::assertArrayNotHasKey('Access-Control-Allow-Origin',
			$this->headersOf($this->resp($domains)),
			'*.example.com must not cover the apex (CSP semantics)');
		$this->origin = 'https://example.com.evil.test';
		self::assertArrayNotHasKey('Access-Control-Allow-Origin',
			$this->headersOf($this->resp($domains)),
			'suffix match must be dot-anchored');
	}

	public function testHttpAndMissingOriginNeverReflect(): void
	{
		$this->origin = 'http://shop.example';
		self::assertArrayNotHasKey('Access-Control-Allow-Origin', $this->headersOf($this->resp()));
		$this->origin = '';
		self::assertArrayNotHasKey('Access-Control-Allow-Origin', $this->headersOf($this->resp()));
	}

	public function testMalformedOrNullDomainsNeverReflect(): void
	{
		$this->origin = 'https://shop.example';
		self::assertArrayNotHasKey('Access-Control-Allow-Origin', $this->headersOf($this->resp('not-json')));
		self::assertArrayNotHasKey('Access-Control-Allow-Origin', $this->headersOf($this->resp(null)));
		self::assertArrayNotHasKey('Access-Control-Allow-Origin', $this->headersOf($this->resp('[]')));
	}
}

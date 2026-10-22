<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Tests\Unit\Support;

use OCA\BookingCheck\Support\PublicNotFound;
use OCP\AppFramework\Http;
use Test\TestCase;

/**
 * The public "nothing here" page must carry a real 404 — a 200 tells
 * crawlers, caches and uptime probes the dead link is a live page.
 */
final class PublicNotFoundTest extends TestCase
{
	public function testRenderCarries404(): void
	{
		$resp = PublicNotFound::render();
		self::assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
	}

	public function testRenderPassesParamsThrough(): void
	{
		$resp = PublicNotFound::render(['recover' => true]);
		$ref = new \ReflectionProperty($resp, 'params');
		$params = $ref->getValue($resp);
		self::assertTrue($params['recover'] ?? false);
	}
}

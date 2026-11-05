<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Navigation-called GET routes must carry #[NoCSRFRequired].
 *
 * The AppFramework SecurityMiddleware runs the requesttoken check on EVERY
 * verb for session requests — a tokenless browser navigation
 * (window.location.href, raw <a href>, method=get form) to a GET route
 * without the attribute fails closed with a 412 before the controller ever
 * runs. bookingcheck intake 20261105T2210Z: GET /api/reports/datev
 * (method=get form, templates/admin/reports.php:29) and the
 * exportZip/downloadStats raw <a href> links were dead on every real
 * click — same class as ticketcheck r30 oauth/begin (@6225db9).
 *
 * Contract: a GET/HEAD route must carry #[NoCSRFRequired] (method or class
 * level) unless it is provably fetch-only. Fetch-only = /api/* consumed by
 * BCK.api (js/app.js attaches the requesttoken header on every verb) or an
 * explicit exemption below. /api/ routes that are navigation-called are
 * listed in NAV_CALLED_API_ROUTES instead and keep the attribute.
 */
final class NavigationGetRoutesCsrfContractTest extends TestCase
{
	/**
	 * /api/ GET routes that ARE reachable via tokenless navigation and must
	 * therefore keep #[NoCSRFRequired] even though the /api/ prefix is
	 * fetch-only by convention.
	 *
	 * @var list<string>
	 */
	private const NAV_CALLED_API_ROUTES = [
		'report#datev',          // method=get form, templates/admin/reports.php
		'report#exportZip',      // raw <a href> download link
		'report#downloadStats',  // raw <a href> download link
	];

	private static function appRoot(): string
	{
		return dirname(__DIR__, 3);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function routes(): array
	{
		$config = require self::appRoot() . '/appinfo/routes.php';
		self::assertIsArray($config['routes'] ?? null);
		return $config['routes'];
	}

	private static function controllerClassName(string $controller): string
	{
		$parts = preg_split('/[_\-\s]/', $controller) ?: [];
		$name = '';
		foreach ($parts as $part) {
			if ($part !== '') {
				$name .= ucfirst($part);
			}
		}
		return $name . 'Controller';
	}

	private static function methodCarriesNoCsrf(string $controller, string $action): bool
	{
		$cls = self::controllerClassName($controller);
		$file = self::appRoot() . '/lib/Controller/' . $cls . '.php';
		self::assertFileExists($file, "Controller file missing for {$controller}");
		$src = (string) file_get_contents($file);
		$attr = '(?:[ \t]*#\[[^\]]+\]\s*\n|[ \t]*/\*\*[^*]*\*+(?:[^/*][^*]*\*+)*/\s*\n)*';
		if (!preg_match('~(' . $attr . ')[ \t]*public function ' . preg_quote($action, '~') . '\s*\(~', $src, $m)) {
			self::fail("Method {$cls}::{$action} not found");
		}
		// Must be the real attribute line — `#[` as the first non-ws token.
		// A docblock line starts with `*` so a comment merely mentioning
		// "#[NoCSRFRequired]" cannot satisfy this (mutation-proven).
		$hasAttr = static fn (string $block): bool
			=> (bool) preg_match('~^[ \t]*#\[[\w\\\\]*NoCSRFRequired\]~m', $block);
		if ($hasAttr($m[1])) {
			return true;
		}
		if (preg_match('~(' . $attr . ')\s*(?:abstract\s+|final\s+)?class\s+' . preg_quote($cls, '~') . '\b~', $src, $cm)) {
			return $hasAttr($cm[1]);
		}
		return false;
	}

	public function testNavigationCalledGetRoutesCarryNoCsrfRequired(): void
	{
		$violations = [];
		foreach (self::routes() as $route) {
			$name = (string)($route['name'] ?? '');
			$url = (string)($route['url'] ?? '');
			$verb = strtoupper((string)($route['verb'] ?? 'GET'));
			if ($name === '' || !str_contains($name, '#') || !in_array($verb, ['GET', 'HEAD'], true)) {
				continue;
			}
			// Fetch-only: /api/* consumed by BCK.api (requesttoken header on
			// every verb) — except routes also called by navigation.
			if (str_starts_with($url, '/api/') && !in_array($name, self::NAV_CALLED_API_ROUTES, true)) {
				continue;
			}
			[$controller, $action] = explode('#', $name, 2);
			if (!self::methodCarriesNoCsrf($controller, $action)) {
				$violations[] = "{$name} {$url}";
			}
		}
		self::assertSame(
			[],
			$violations,
			'GET routes reachable via tokenless navigation must carry #[NoCSRFRequired] '
				. '(SecurityMiddleware 412s them otherwise): ' . implode(', ', $violations),
		);
	}

	public function testReportExportsAreReachableByBrowserNavigation(): void
	{
		foreach (['datev', 'exportZip', 'downloadStats'] as $action) {
			self::assertTrue(
				self::methodCarriesNoCsrf('report', $action),
				"report#{$action} is a tokenless <a href>/get-form download — "
					. 'without the attribute every seller gets a 412',
			);
		}
	}
}

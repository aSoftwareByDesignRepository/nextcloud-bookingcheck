<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit\Service;

use OCA\BookingCheck\Service\DatevExportService;
use OCP\IDBConnection;
use OCA\BookingCheck\Service\SettingsService;
use Test\TestCase;

/**
 * DATEV EXTF is pipe-… ';'-delimited fixed-format: a buyer name or
 * company carrying the field separator forges extra columns, CR/LF
 * forges whole ledger rows. `datevField` is the write-boundary guard;
 * it is private precisely so every substituted value is forced
 * through it — the reflection keeps the regression net on the guard
 * itself, not on a CSV-shaped coincidence.
 */
final class DatevExportServiceTest extends TestCase
{
	private function call(string $method, string $arg): string
	{
		$svc = new DatevExportService(
			$this->createMock(IDBConnection::class),
			$this->createMock(SettingsService::class),
		);
		$m = new \ReflectionMethod($svc, $method);
		return (string)$m->invoke($svc, $arg);
	}

	public function testFieldSeparatorStripped(): void
	{
		self::assertSame('Meyer Schmidt', $this->call('datevField', 'Meyer;Schmidt'));
	}

	public function testRecordInjectionStripped(): void
	{
		$out = $this->call('datevField', "evil\r\n1000,00;S;forged");
		self::assertStringNotContainsString("\r", $out);
		self::assertStringNotContainsString("\n", $out);
		self::assertStringNotContainsString(';', $out);
	}

	public function testQuoteEscapesDoubleQuotes(): void
	{
		self::assertSame('"say ""hi"""', $this->call('q', 'say "hi"'));
	}
}

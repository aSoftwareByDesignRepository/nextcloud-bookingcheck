<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;

/**
 * Migration steps must run on MariaDB AND PostgreSQL. Raw SQL inside
 * migrations bypasses the query builder, so MySQL-only syntax compiles
 * and passes CI-on-MariaDB while exploding on the first PG upgrade.
 * This test is the gate: scan every migration for constructs that are
 * not portable. (Caught live: GROUP_CONCAT, UPDATE…JOIN counter merges,
 * DELETE…INNER JOIN, backtick-quoted *PREFIX* — NC rewrites only the
 * prefix token, quoting passes through to the driver verbatim.)
 */
final class MigrationPortabilityTest extends TestCase
{
	private const MIGRATION_DIR = __DIR__ . '/../../../lib/Migration';

	/** @return array<string, string> pattern → human explanation */
	private static function forbiddenPatterns(): array
	{
		return [
			'/\bGROUP_CONCAT\s*\(/i'
				=> 'GROUP_CONCAT() is MySQL-only (PG: string_agg) — group in PHP instead',
			'/\bINSERT\s+IGNORE\b/i'
				=> 'INSERT IGNORE is MySQL-only — insert + catch REASON_UNIQUE_CONSTRAINT_VIOLATION',
			'/\bON\s+DUPLICATE\s+KEY\b/i'
				=> 'ON DUPLICATE KEY is MySQL-only — insert + catch unique violation',
			'/\bREPLACE\s+INTO\b/i'
				=> 'REPLACE INTO is MySQL-only — use insert-or-update via mapper',
			'/\bUPDATE\s+\S+\s+\w+\s+JOIN\b/i'
				=> 'UPDATE t alias JOIN … is MySQL-only multi-table syntax — read-then-write instead',
			'/\bDELETE\s+\w+\s+FROM\s+\w+\s+(INNER\s+)?JOIN\b/i'
				=> 'DELETE alias FROM … JOIN is MySQL-only — use NOT IN with a derived-table wrap',
			'/`\*PREFIX\*|\*PREFIX\*\w+`/'
				=> 'backtick quoting is MySQL-only; NC rewrites *PREFIX* but passes backticks verbatim',
			'/\bLIMIT\s+\d+\s*,\s*\d+\b/i'
				=> 'LIMIT offset,count is MySQL-only — use LIMIT n OFFSET m or the query builder',
		];
	}

	public function testMigrationDirectoryExists(): void
	{
		$files = glob(self::MIGRATION_DIR . '/Version*.php');
		self::assertNotEmpty($files, 'no migrations found — is the test running from the app dir?');
	}

	/**
	 * @dataProvider migrationFileProvider
	 */
	public function testMigrationUsesPortableSql(string $file): void
	{
		$src = (string)file_get_contents($file);
		// strip comments: docblocks legitimately mention these constructs
		// in prose; only executable code is in scope.
		$codeOnly = (string)preg_replace('#/\*.*?\*/#s', '', $src);
		$codeOnly = (string)preg_replace('#//[^\n]*#', '', $codeOnly);
		$codeOnly = (string)preg_replace('#[ \t]*\*[^\n]*#', '', $codeOnly); // docblock tails

		foreach (self::forbiddenPatterns() as $pattern => $why) {
			if (preg_match($pattern, $codeOnly, $m)) {
				self::fail(basename($file) . ': MySQL-only SQL "' . $m[0] . '" — ' . $why);
			}
		}
		self::addToAssertionCount(1);
	}

	/** @return iterable<string, array{string}> */
	public static function migrationFileProvider(): iterable
	{
		foreach (glob(self::MIGRATION_DIR . '/Version*.php') ?: [] as $f) {
			yield basename($f) => [$f];
		}
	}
}

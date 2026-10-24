<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Browser loads l10n/*.js as classic scripts (OC.L10N.register).
 * A third register() argument (pluralForm string) is compiled with new Function()
 * under legacy shims and violates strict CSP (no unsafe-eval). Plural resolution
 * is covered by core CLDR tables keyed on the active locale; the .json
 * pluralForm stays for the PHP $l->n() path.
 */
final class L10nJsCspContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 2);
	}

	public function testLocaleJsFilesUseTwoArgRegisterOnly(): void
	{
		$dir = $this->root . '/l10n';
		$files = glob($dir . '/*.js') ?: [];
		self::assertNotEmpty($files, 'expected l10n/*.js');

		foreach ($files as $path) {
			$src = (string)file_get_contents($path);
			$base = basename($path);
			self::assertStringContainsString('OC.L10N.register(', $src, $base);
			// Catch both `},"nplurals=…");` and `},\n"nplurals=…"\n);` third-arg forms.
			self::assertDoesNotMatchRegularExpression(
				'/"nplurals=\d+;\s*plural=/',
				$src,
				$base . ' must not pass pluralForm as OC.L10N.register() third argument (CSP unsafe-eval)',
			);
			self::assertMatchesRegularExpression(
				'/\}\s*\)\s*;\s*$/',
				$src,
				$base . ' must end with two-argument OC.L10N.register(app, translations);',
			);
			self::assertTrue(
				self::jsStringAwareParenBalanceOk($src),
				$base . ' has unbalanced () outside of string literals',
			);
		}
	}

	public function testRegenerateScriptDocumentsCspConstraint(): void
	{
		$src = (string)file_get_contents($this->root . '/scripts/regenerate-l10n-js.php');
		self::assertStringContainsString('new Function()', $src);
		self::assertStringContainsString('Do NOT pass pluralForm as a third argument', $src);
		self::assertStringContainsString('$lines[] = ");\n";', $src);
		self::assertSame(
			0,
			substr_count($src, '$pluralForm'),
			'regenerate-l10n-js.php must not read or emit $pluralForm',
		);
		self::assertDoesNotMatchRegularExpression(
			'/pluralForm !== null|\$pluralForm|json_encode\(\$pluralForm\)/',
			$src,
			'regenerate-l10n-js.php must not emit pluralForm third register() arg',
		);
	}

	/**
	 * Minimal string-aware () balance check — catches broken register() calls without Node.
	 */
	private static function jsStringAwareParenBalanceOk(string $src): bool
	{
		$depth = 0;
		$len = strlen($src);
		$inString = false;
		$escape = false;
		for ($i = 0; $i < $len; $i++) {
			$ch = $src[$i];
			if ($inString) {
				if ($escape) {
					$escape = false;
					continue;
				}
				if ($ch === '\\') {
					$escape = true;
					continue;
				}
				if ($ch === '"') {
					$inString = false;
				}
				continue;
			}
			if ($ch === '"') {
				$inString = true;
				continue;
			}
			if ($ch === '(') {
				$depth++;
			} elseif ($ch === ')') {
				$depth--;
				if ($depth < 0) {
					return false;
				}
			}
		}

		return !$inString && $depth === 0;
	}
}

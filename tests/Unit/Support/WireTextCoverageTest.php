<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * l10n_unlocalized_error_surface — every literal that can reach the wire
 * envelope ({success:false, error, message, fields}) must exist in
 * l10n/en.json, or ErrorText::t() passes it through as English to every
 * localized requester.
 *
 * The source-coverage gate scans ->t()/->n() call sites only — it is
 * structurally blind to exception-ctor args and fields-map assignments,
 * which is exactly how the 20261023 tail shipped (ManageController field
 * error, Slot/Settings validation messages, capi pass-deny strings,
 * composed 'Unknown group: id'). This test scans those channels:
 *
 *   ->fail($status, 'CODE', 'msg' [, $fields])    envelope message
 *   ->envelope($status, 'CODE', 'msg' [, $fields]) middleware message
 *   ->tx('msg')                                   composed-message heads
 *   new StateConflictException('msg' [, 'CODE'])  getMessage() → envelope
 *   new CapacityException('msg')                  getMessage() → envelope
 *   new ValidationException(['field' => 'msg'])   field values → envelope
 *   $fields['f'] = 'msg' / $fields = ['f'=>'msg'] deferred field maps
 *
 * NOT wire-bound (intentionally excluded): ProRequiredException's ctor
 * arg (a feature key — the wire message is a fixed cataloged string);
 * Unauthorized/NotFound/AppDoorDenied/RateLimit/PspException ctor args
 * (the envelope substitutes a fixed cataloged message, the ctor arg is
 * log context at most); UpgradeBackupException (occ/repair-bound);
 * framework exceptions (collapse to 'Internal error.').
 */
final class WireTextCoverageTest extends TestCase
{
	private const LIB = __DIR__ . '/../../../lib';
	private const EN_JSON = __DIR__ . '/../../../l10n/en.json';

	/** @return array<string,string> literal → source file */
	private function wireLiterals(): array
	{
		$found = [];
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator(self::LIB, \FilesystemIterator::SKIP_DOTS));
		foreach ($it as $file) {
			/** @var \SplFileInfo $file */
			if ($file->getExtension() !== 'php' || str_contains($file->getPath(), 'Vendor')) {
				continue;
			}
			$src = self::stripComments((string)file_get_contents($file->getPathname()));
			$rel = 'lib/' . substr($file->getPathname(), strlen(self::LIB) + 1);

			// ->fail( / ->envelope( — $status, 'CODE', 'msg' arg shape
			foreach (['fail', 'envelope'] as $call) {
				if (preg_match_all(
					'/->' . $call . '\(\s*\d+\s*,\s*\'(?:[^\'\\\\]|\\\\.)*\'\s*,\s*\'((?:[^\'\\\\]|\\\\.)*)\'/',
					$src, $m)) {
					foreach ($m[1] as $lit) {
						$found[self::unesc($lit)] ??= $rel;
					}
				}
			}
			// ->tx('head') — composed-message heads take a single arg
			if (preg_match_all(
				'/->tx\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*[,)]/', $src, $m)) {
				foreach ($m[1] as $lit) {
					$found[self::unesc($lit)] ??= $rel;
				}
			}
			// ErrorText::t($l10n, 'msg') — literal msgids at the boundary
			if (preg_match_all(
				'/ErrorText::t\(\s*[^,]+,\s*\'((?:[^\'\\\\]|\\\\.)*)\'/',
				$src, $m)) {
				foreach ($m[1] as $lit) {
					$found[self::unesc($lit)] ??= $rel;
				}
			}
			// ctor arg → getMessage() → envelope message
			if (preg_match_all(
				'/new\s+(?:[\w\\\\]+)?(?:StateConflictException|CapacityException)\s*\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/',
				$src, $m)) {
				foreach ($m[1] as $lit) {
					$found[self::unesc($lit)] ??= $rel;
				}
			}
			// ValidationException(['f' => 'msg']) — balanced-array values
			if (preg_match_all(
				'/new\s+(?:[\w\\\\]+)?ValidationException\s*\(\s*\[/', $src, $m,
				PREG_OFFSET_CAPTURE)) {
				foreach ($m[0] as [$tok, $pos]) {
					foreach (self::mapValues(self::balanced($src, $pos + strlen($tok) - 1)) as $v) {
						$found[$v] ??= $rel;
					}
				}
			}
			// $fields['f'] = 'msg' / $fields = ['f' => 'msg']
			if (preg_match_all(
				'/\$fields\s*\[[^\]]*\]\s*=\s*\'((?:[^\'\\\\]|\\\\.)*)\'/', $src, $m)) {
				foreach ($m[1] as $lit) {
					$found[self::unesc($lit)] ??= $rel;
				}
			}
			if (preg_match_all('/\$fields\s*=\s*\[/', $src, $m, PREG_OFFSET_CAPTURE)) {
				foreach ($m[0] as [$tok, $pos]) {
					foreach (self::mapValues(self::balanced($src, $pos + strlen($tok) - 1)) as $v) {
						$found[$v] ??= $rel;
					}
				}
			}
		}
		return $found;
	}

	public function testWireBoundLiteralsAreCataloged(): void
	{
		$catalog = json_decode(
			(string)file_get_contents(self::EN_JSON), true, 512, JSON_THROW_ON_ERROR);
		$keys = array_flip(array_keys($catalog['translations'] ?? []));

		$missing = [];
		foreach ($this->wireLiterals() as $lit => $file) {
			if ($lit !== '' && !isset($keys[$lit])) {
				$missing[] = "$lit  <- $file";
			}
		}
		self::assertSame([], $missing,
			"wire-bound literals missing from l10n/en.json:\n" . implode("\n", $missing));
	}

	public function testExtractorHasTeeth(): void
	{
		$found = $this->wireLiterals();
		// Representative known-good wire strings — if the extractor ever
		// breaks (regex drift, path move) these disappear and the suite
		// would silently pass on an empty scan.
		foreach (['Not found.', 'Please check the highlighted fields.',
			'This answer is required.', 'Unknown group',
			'Pass use limit reached.', 'Inventory counter underflow.'] as $known) {
			self::assertArrayHasKey($known, $found, "extractor missed $known");
		}
		self::assertGreaterThan(100, count($found));
	}

	private static function stripComments(string $src): string
	{
		$src = (string)preg_replace('/\/\*.*?\*\//s', '', $src);
		$src = (string)preg_replace('/(?m)^\s*\/\/.*$/', '', $src);
		return (string)preg_replace('/(?m)(?<![:\'"])\/\/.*$/', '', $src);
	}

	private static function unesc(string $s): string
	{
		return strtr($s, ["\\'" => "'", '\\"' => '"', '\\n' => "\n",
			'\\t' => "\t", '\\\\' => '\\']);
	}

	/** Extract the balanced [...] literal starting at $pos (points at '['). */
	private static function balanced(string $src, int $pos): string
	{
		$depth = 0;
		$inStr = false;
		$quote = '';
		for ($i = $pos; $i < strlen($src); $i++) {
			$c = $src[$i];
			if ($inStr) {
				if ($c === '\\') {
					$i++;
				} elseif ($c === $quote) {
					$inStr = false;
				}
				continue;
			}
			if ($c === "'" || $c === '"') {
				$inStr = true;
				$quote = $c;
			} elseif ($c === '[') {
				$depth++;
			} elseif ($c === ']') {
				if (--$depth === 0) {
					return substr($src, $pos, $i - $pos + 1);
				}
			}
		}
		return '';
	}

	/** @return list<string> 'k' => 'literal' value literals of a [ … ] map */
	private static function mapValues(string $arrayLit): array
	{
		preg_match_all('/=>\s*\'((?:[^\'\\\\]|\\\\.)*)\'|=>\s*"((?:[^"\\\\]|\\\\.)*)"/',
			$arrayLit, $m, PREG_SET_ORDER);
		$out = [];
		foreach ($m as $mm) {
			$v = $mm[1] !== '' ? $mm[1] : ($mm[2] ?? '');
			if ($v !== '') {
				$out[] = self::unesc($v);
			}
		}
		return $out;
	}
}

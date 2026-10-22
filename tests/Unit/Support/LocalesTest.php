<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit\Support;

use OCA\BookingCheck\Support\Locales;
use PHPUnit\Framework\TestCase;

class LocalesTest extends TestCase
{
	/**
	 * @dataProvider cases
	 */
	public function testCanonicalize(mixed $in, string $expected): void
	{
		$this->assertSame($expected, Locales::canonicalize($in));
	}

	public static function cases(): array
	{
		return [
			'browser pt-BR' => ['pt-BR', 'pt_BR'],
			'bare pt' => ['pt', 'pt_BR'],
			'underscored' => ['pt_br', 'pt_BR'],
			'upper' => ['PT_BR', 'pt_BR'],
			'regional variant of shipped lang' => ['de-AT', 'de'],
			'regional en' => ['en-US', 'en'],
			'shipped exact' => ['fr', 'fr'],
			'whitespace' => ['  nl ', 'nl'],
			'unshipped valid shape passes through' => ['ja', 'ja'],
			'unshipped regional passes through' => ['zh-TW', 'zh_tw'],
			'invalid shape falls back' => ['x1?', 'en'],
			'non-string falls back' => [null, 'en'],
			'array falls back' => [['de'], 'en'],
			'idempotent' => ['pt_BR', 'pt_BR'],
		];
	}
}

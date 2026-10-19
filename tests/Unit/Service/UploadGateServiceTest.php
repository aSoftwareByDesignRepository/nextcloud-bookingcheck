<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit\Service;

use OCA\BookingCheck\Exception\ValidationException;
use OCA\BookingCheck\Service\UploadGateService;
use Test\TestCase;

final class UploadGateServiceTest extends TestCase
{
	private UploadGateService $gate;
	/** @var list<string> */
	private array $tmpFiles = [];

	protected function setUp(): void
	{
		$this->gate = new UploadGateService();
	}

	protected function tearDown(): void
	{
		foreach ($this->tmpFiles as $f) {
			@unlink($f);
		}
	}

	private function upload(string $name, string $body): array
	{
		$tmp = tempnam(sys_get_temp_dir(), 'bckg-');
		file_put_contents($tmp, $body);
		$this->tmpFiles[] = $tmp;
		return ['name' => $name, 'tmp_name' => $tmp, 'size' => strlen($body),
			'type' => 'application/octet-stream', 'error' => UPLOAD_ERR_OK];
	}

	private function fieldError(array $file): string
	{
		try {
			$this->gate->inspect($file);
		} catch (ValidationException $e) {
			return (string)array_values($e->getFields())[0];
		}
		return '';
	}

	public function testHonestPdfPasses(): void
	{
		$r = $this->gate->inspect($this->upload('doc.pdf', "%PDF-1.4 body"));
		self::assertSame('doc.pdf', $r['name']);
	}

	public function testDisallowedExtensionRejected(): void
	{
		self::assertStringContainsString('not allowed',
			$this->fieldError($this->upload('shell.php', '<?php echo 1;')));
	}

	public function testExeExtensionRejected(): void
	{
		self::assertStringContainsString('not allowed',
			$this->fieldError($this->upload('run.exe', "MZ\x90\x00")));
	}

	public function testMagicByteMismatchRejected(): void
	{
		// a .pdf that is actually a ZIP must not pass
		self::assertStringContainsString('does not match',
			$this->fieldError($this->upload('fake.pdf', "PK\x03\x04zip")));
	}

	public function testPngMagicVerified(): void
	{
		self::assertStringContainsString('does not match',
			$this->fieldError($this->upload('img.png', 'not-a-png')));
		$r = $this->gate->inspect($this->upload('img.png', "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A" . str_repeat('x', 20)));
		self::assertSame('img.png', $r['name']);
	}

	public function testWebpNeedsWebpAtOffset8(): void
	{
		// RIFF header but the wrong fourcc at offset 8
		self::assertStringContainsString('does not match',
			$this->fieldError($this->upload('pic.webp', "RIFF\x00\x00\x00\x00AVI ")));
		$r = $this->gate->inspect($this->upload('pic.webp', "RIFF\x00\x00\x00\x00WEBP"));
		self::assertSame('pic.webp', $r['name']);
	}

	public function testMacroEnabledDocxRejected(): void
	{
		$tmp = tempnam(sys_get_temp_dir(), 'bckg-');
		$this->tmpFiles[] = $tmp;
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($tmp, \ZipArchive::OVERWRITE) === true);
		$zip->addFromString('word/document.xml', '<doc/>');
		$zip->addFromString('word/vbaProject.bin', 'macro-bytes');
		$zip->close();
		self::assertStringContainsString('Macro',
			$this->fieldError(['name' => 'plan.docx', 'tmp_name' => $tmp,
				'size' => filesize($tmp), 'type' => 'x', 'error' => UPLOAD_ERR_OK]));
	}

	public function testCleanDocxPasses(): void
	{
		$tmp = tempnam(sys_get_temp_dir(), 'bckg-');
		$this->tmpFiles[] = $tmp;
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($tmp, \ZipArchive::OVERWRITE) === true);
		$zip->addFromString('word/document.xml', '<doc/>');
		$zip->close();
		$r = $this->gate->inspect(['name' => 'plan.docx', 'tmp_name' => $tmp,
			'size' => filesize($tmp), 'type' => 'x', 'error' => UPLOAD_ERR_OK]);
		self::assertSame('plan.docx', $r['name']);
	}

	public function testCorruptZipRejected(): void
	{
		self::assertStringContainsString('Invalid Office',
			$this->fieldError($this->upload('broken.docx', "PK\x03\x04not-a-real-zip")));
	}

	public function testZeroSizeRejected(): void
	{
		self::assertStringContainsString('out of bounds',
			$this->fieldError($this->upload('empty.txt', '')));
	}

	public function testUploadErrorRejected(): void
	{
		$f = $this->upload('doc.pdf', '%PDF');
		$f['error'] = UPLOAD_ERR_PARTIAL;
		self::assertStringContainsString('failed', $this->fieldError($f));
	}

	public function testPathTraversalInNameIsStripped(): void
	{
		$r = $this->gate->inspect($this->upload('../../etc/passwd.txt', 'hello'));
		self::assertSame('passwd.txt', $r['name']);
	}

	public function testUnsafeNameCharsSanitized(): void
	{
		$r = $this->gate->inspect($this->upload('report <b>.txt', 'hello'));
		self::assertMatchesRegularExpression('/^[\w.\- ]+$/', $r['name']);
	}

	public function testTextTypesHaveNoMagicRequirement(): void
	{
		// txt/csv/md carry no magic bytes — content passes by extension
		self::assertSame('a.csv', $this->gate->inspect(
			$this->upload('a.csv', "name,value\nx,1"))['name']);
	}
}

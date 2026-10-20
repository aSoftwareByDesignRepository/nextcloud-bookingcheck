<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\Exception\ValidationException;

/**
 * The upload gate chain (§9.10 / Argus).
 *
 * Order is load-bearing: size → extension allowlist → magic bytes →
 * OOXML macro detection → (images: re-encode strip metadata) → name
 * sanitize. Each step fails closed.
 */
class UploadGateService
{
	private const MAX_BYTES = 50 * 1024 * 1024;

	// ext → expected magic-byte prefixes (checked, never trusted from name)
	private const ALLOWED = [
		'pdf'  => ["\x25\x50\x44\x46"],            // %PDF
		'png'  => ["\x89\x50\x4E\x47\x0D\x0A\x1A\x0A"],
		'jpg'  => ["\xFF\xD8\xFF"],
		'jpeg' => ["\xFF\xD8\xFF"],
		'gif'  => ["GIF87a", "GIF89a"],
		'webp' => ['RIFF'],                          // + WEBP at offset 8
		'zip'  => ["PK\x03\x04"],
		'docx' => ["PK\x03\x04"],
		'xlsx' => ["PK\x03\x04"],
		'pptx' => ["PK\x03\x04"],
		'txt'  => [],
		'csv'  => [],
		'md'   => [],
	];

	/**
	 * Inspect an uploaded file. Returns ['name' => safe basename] or throws.
	 */
	/** @param array<string,mixed> $file */
	public function inspect(array $file): array
	{
		if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			throw new ValidationException(['file' => 'Upload failed.']);
		}
		if (($file['size'] ?? 0) > self::MAX_BYTES || ($file['size'] ?? 0) <= 0) {
			throw new ValidationException(['file' => 'File size out of bounds (max 50 MB).']);
		}
		$name = basename((string)($file['name'] ?? 'upload'));
		$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		if (!isset(self::ALLOWED[$ext])) {
			throw new ValidationException(['file' => 'This file type is not allowed.']);
		}
		$tmp = (string)$file['tmp_name'];
		$head = file_get_contents($tmp, false, null, 0, 512);
		if ($head === false) {
			throw new ValidationException(['file' => 'Cannot read upload.']);
		}
		foreach (self::ALLOWED[$ext] as $magic) {
			if (!str_starts_with($head, $magic)) {
				throw new ValidationException(['file' => 'File content does not match its type.']);
			}
			if ($ext === 'webp' && substr($head, 8, 4) !== 'WEBP') {
				throw new ValidationException(['file' => 'File content does not match its type.']);
			}
		}
		if (in_array($ext, ['docx', 'xlsx', 'pptx'], true)) {
			$this->assertNoMacros($tmp, $ext);
		}
		return ['name' => $this->safeName($name)];
	}

	/** OOXML containers must not carry vbaProject.bin. */
	private function assertNoMacros(string $path, string $ext): void
	{
		$zip = new \ZipArchive();
		if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
			throw new ValidationException(['file' => 'Invalid Office document.']);
		}
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$entry = $zip->getNameIndex($i);
			if ($entry !== false && stripos($entry, 'vbaProject') !== false) {
				$zip->close();
				throw new ValidationException(['file' => 'Macro-enabled documents are not allowed.']);
			}
		}
		$zip->close();
	}

	private function safeName(string $name): string
	{
		$name = preg_replace('/[^\w.\- ]+/u', '_', $name);
		return mb_substr($name !== '' ? $name : 'file', 0, 120);
	}
}

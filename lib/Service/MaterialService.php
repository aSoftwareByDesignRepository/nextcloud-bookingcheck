<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\Db\Material;
use OCA\BookingCheck\Db\MaterialLibMapper;
use OCA\BookingCheck\Db\MaterialMapper;
use OCA\BookingCheck\Exception\NotFoundException;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\IAppData;

/**
 * Material storage + gated delivery.
 *
 * file_ref is an opaque id inside the app-managed appdata tree — never a
 * filesystem path, never guessable from names. Downloads stream through
 * this service only after entitlement passes upstream.
 */
class MaterialService
{
	public function __construct(
		private readonly MaterialMapper $materials,
		private readonly MaterialLibMapper $library,
		private readonly IAppData $appData,
	) {
	}

	/** Store an uploaded file; returns the opaque file_ref. */
	public function store(string $filename, $stream): string
	{
		$ref = bin2hex(random_bytes(16));
		$dir = $this->materialsDir();
		$dir->newFile($ref, $stream);
		return $ref;
	}

	/**
	 * Stream a material's bytes. Resolves live library links first.
	 * Filename leaves via RFC5987 Content-Disposition — safe chars only.
	 */
	public function stream(int $materialId): StreamResponse|DataDownloadResponse
	{
		$m = $this->materials->find($materialId);
		if ($m === null) {
			throw new NotFoundException();
		}
		$ref = $m->getFileRef();
		$title = $m->getTitle();
		if ($m->getLibraryId() !== null) {
			$lib = $this->library->find((int)$m->getLibraryId());
			if ($lib === null || $lib->getFileRef() === null) {
				throw new NotFoundException();
			}
			$ref = $lib->getFileRef();
			$title = $lib->getTitle();
		}
		if ($ref === null) {
			throw new NotFoundException();
		}
		$dir = $this->materialsDir();
		if (!$dir->fileExists($ref)) {
			throw new NotFoundException();
		}
		$file = $dir->getFile($ref);
		$resp = new StreamResponse($file->fopen('rb'));
		$resp->addHeader('Content-Type', 'application/octet-stream');
		$resp->addHeader('X-Content-Type-Options', 'nosniff');
		$resp->addHeader('Content-Disposition', self::contentDisposition($title));
		return $resp;
	}

	public function delete(string $fileRef): void
	{
		try {
			$dir = $this->materialsDir();
			if ($dir->fileExists($fileRef)) {
				$dir->getFile($fileRef)->delete();
			}
		} catch (\Throwable) {
			// orphan cleanup is a job, not a fatal
		}
	}

	/** RFC5987 filename — CRLF+quote-stripped, UTF-8 filename*= fallback. */
	public static function contentDisposition(string $name): string
	{
		$ascii = preg_replace('/[^\x20-\x7E]/', '_', $name);
		$ascii = str_replace(['"', '\\', "\r", "\n"], '_', $ascii);
		$ascii = trim($ascii) !== '' ? $ascii : 'download';
		return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name);
	}

	private function materialsDir(): \OCP\Files\SimpleFS\ISimpleFolder
	{
		try {
			return $this->appData->getFolder('materials');
		} catch (\Throwable) {
			return $this->appData->newFolder('materials');
		}
	}
}

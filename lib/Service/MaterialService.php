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
use Psr\Log\LoggerInterface;

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
		private readonly ?LoggerInterface $logger = null,
	) {
	}

	/** Store an uploaded file; returns the opaque file_ref. */
	/** @param resource $stream */
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
		$stream = $file->read();
		if ($stream === false) {
			throw new NotFoundException();
		}
		$resp = new StreamResponse($stream);
		$resp->addHeader('Content-Type', 'application/octet-stream');
		$resp->addHeader('X-Content-Type-Options', 'nosniff');
		$resp->addHeader('Content-Disposition', self::contentDisposition($title));
		return $resp;
	}

	/**
	 * NH-14 — inline preview. Only magic-byte-verified PDF/JPEG/PNG/
	 * GIF/WEBP render inline; everything else (incl. SVG/HTML — script
	 * vectors) falls back to the attachment disposition.
	 */
	public function streamPreview(int $materialId): StreamResponse|DataDownloadResponse
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
		$head = $file->read();
		if ($head === false) {
			throw new NotFoundException();
		}
		$magic = fread($head, 12);
		fclose($head);
		if ($magic === false) {
			throw new NotFoundException();
		}
		$mime = match (true) {
			str_starts_with($magic, "%PDF-") => 'application/pdf',
			str_starts_with($magic, "\xFF\xD8\xFF") => 'image/jpeg',
			str_starts_with($magic, "\x89PNG\x0D\x0A\x1A\x0A") => 'image/png',
			str_starts_with($magic, 'GIF87a') || str_starts_with($magic, 'GIF89a') => 'image/gif',
			str_starts_with($magic, 'RIFF') && substr($magic, 8, 4) === 'WEBP' => 'image/webp',
			default => null,
		};
		if ($mime === null) {
			return $this->stream($materialId); // not previewable → download
		}
		$stream = $file->read();
		if ($stream === false) {
			throw new NotFoundException();
		}
		$resp = new StreamResponse($stream);
		$resp->addHeader('Content-Type', $mime);
		$resp->addHeader('X-Content-Type-Options', 'nosniff');
		$resp->addHeader('Content-Disposition', 'inline; filename="preview"');
		$resp->addHeader('Cache-Control', 'private, max-age=300');
		// seller material is untrusted content rendered on the portal origin —
		// a PDF can carry embedded JS that some viewers execute. sandbox the
		// document: it still renders, but gets no script and no origin rights
		$resp->addHeader('Content-Security-Policy', 'sandbox');
		return $resp;
	}

	public function delete(string $fileRef): void
	{
		try {
			$dir = $this->materialsDir();
			if ($dir->fileExists($fileRef)) {
				$dir->getFile($fileRef)->delete();
			}
		} catch (\Throwable $e) {
			// best-effort orphan cleanup — a failed delete is janitor work
			$this->logger?->warning('Material delete failed for ' . $fileRef . ': ' . $e->getMessage());
		}
	}

	/** RFC5987 filename — CRLF+quote-stripped, UTF-8 filename*= fallback. */
	public static function contentDisposition(string $name): string
	{
		$ascii = preg_replace('/[^\x20-\x7E]/', '_', $name) ?? '';
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

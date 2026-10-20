<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\Db\Offering;
use OCA\BookingCheck\Db\Order;
use OCA\BookingCheck\Db\OrderLineMapper;
use OCA\BookingCheck\Db\Upload;
use OCA\BookingCheck\Db\UploadMapper;
use OCA\BookingCheck\Exception\NotFoundException;
use OCA\BookingCheck\Exception\StateConflictException;
use OCA\BookingCheck\Exception\ValidationException;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\IAppData;

/**
 * Attendee uploads — buyers share files (certificates, docs) with each
 * other or the seller. Visibility:
 *   - uploader always sees their own files (matched by order email)
 *   - scope 'offering'  → every paid attendee of the offering
 *   - scope 'occurrence'→ only paid attendees of the same target
 * file_ref is opaque; bytes stream only through this service.
 */
class UploadService
{
	public function __construct(
		private readonly UploadMapper $uploads,
		private readonly OrderLineMapper $lines,
		private readonly UploadGateService $gate,
		private readonly LicenseService $license,
		private readonly IAppData $appData,
		private readonly AvScanService $av,
	) {
	}

	public function enabledFor(Offering $o): bool
	{
		return (int)$o->getSharingEnabled() === 1
			&& $this->license->hasFeature('uploads');
	}

	/**
	 * Store an attendee upload. Returns the new entity.
	 * @param array<string,mixed> $file the $_FILES-style entry
	 */
	public function store(Order $order, Offering $o, int $lineId, string $displayName, array $file): Upload
	{
		if ($order->getStatus() !== 'paid') {
			throw new NotFoundException();
		}
		if (!$this->enabledFor($o)) {
			throw new NotFoundException(); // uniform — no feature oracle
		}
		$displayName = trim($displayName);
		if ($displayName === '' || mb_strlen($displayName) > 60) {
			throw new ValidationException(['display_name' => 'Choose a short name to display.']);
		}
		// the line must belong to this order — the id is enumerable
		$line = $this->lines->find($lineId);
		if ($line === null || (int)$line->getOrderId() !== (int)$order->getId()
			|| $line->getLineKind() !== 'seat') {
			throw new NotFoundException();
		}
		$size = (int)($file['size'] ?? 0);
		if ($size > (int)$o->getUploadMaxMb() * 1024 * 1024) {
			throw new ValidationException(['file' => 'File exceeds the per-file limit.']);
		}
		$live = $this->uploads->liveBytesForOffering((int)$o->getId());
		if ($live + $size > (int)$o->getUploadQuotaMb() * 1024 * 1024) {
			throw new ValidationException(['file' => 'The upload quota for this offering is full.']);
		}
		$info = $this->gate->inspect($file); // magic bytes + macro sweep
		$tmp = (string)$file['tmp_name'];
		// NH-13/NN-21: when AV is enabled the scan is fail-CLOSED —
		// 'infected' and 'unscanned' (daemon down) both refuse storage
		$mime = (string)($file['type'] ?? 'application/octet-stream');
		$avState = $this->av->scan($tmp, $mime);
		if ($avState === 'infected' || $avState === 'unscanned') {
			throw new ValidationException(['file' => 'The file could not be accepted.']);
		}
		$ref = bin2hex(random_bytes(16));
		$thumbRef = $this->makeThumb($tmp, $mime);
		$dir = $this->dir();
		$stream = fopen($tmp, 'rb');
		if ($stream === false) {
			throw new ValidationException(['file' => 'Cannot read upload.']);
		}
		try {
			$dir->newFile($ref, $stream); // consumes (closes) the stream
		} finally {
			if (is_resource($stream)) {
				fclose($stream);
			}
		}
		$u = new Upload();
		$u->setOfferingId((int)$o->getId());
		$u->setOccurrenceId($lineId);
		$u->setUploaderEmail((string)$order->getBuyerEmail());
		$u->setDisplayName($displayName);
		$u->setFileRef($ref);
		$u->setSizeBytes($size);
		$u->setSha256((string)hash_file('sha256', $tmp));
		$u->setMime($mime);
		$u->setThumbRef($thumbRef);
		$u->setAvState($avState);
		$u->setCreatedAt(new \DateTimeImmutable());
		return $this->uploads->insert($u);
	}

	/**
	 * @return list<array<string,mixed>> uploads the order's buyer may see
	 */
	public function listFor(Order $order, Offering $o): array
	{
		if ($order->getStatus() !== 'paid' || !$this->enabledFor($o)) {
			return [];
		}
		$scope = (string)$o->getSharingScope() === 'offering' ? 'offering' : 'occurrence';
		$occs = [];
		foreach ($this->lines->findByOrder((int)$order->getId()) as $l) {
			if ($l->getLineKind() === 'seat') {
				$occs[] = ['type' => (string)$l->getTargetType(), 'id' => (int)$l->getTargetId()];
			}
		}
		$email = (string)$order->getBuyerEmail();
		$out = [];
		foreach ($this->uploads->visibleTo((int)$o->getId(), $email, $occs, $scope) as $u) {
			$row = $u->jsonSerialize();
			$row['own'] = strcasecmp((string)$u->getUploaderEmail(), $email) === 0;
			$out[] = $row;
		}
		return $out;
	}

	/** Streaming entitlement: uploader, scoped attendee, or admin (checked upstream). */
	public function streamFor(Upload $u, ?Order $viewerOrder = null, Offering $o = null): StreamResponse
	{
		$this->assertViewable($u, $viewerOrder, $o);
		$dir = $this->dir();
		$ref = (string)$u->getFileRef();
		if (!$dir->fileExists($ref)) {
			throw new NotFoundException();
		}
		$resp = new StreamResponse($dir->getFile($ref)->fopen('rb'));
		$resp->addHeader('Content-Type', 'application/octet-stream');
		$resp->addHeader('X-Content-Type-Options', 'nosniff');
		$resp->addHeader('Content-Disposition', MaterialService::contentDisposition('upload-' . (int)$u->getId()));
		return $resp;
	}

	/** Soft-delete; only the uploader (email match) may remove their file. */
	public function delete(Upload $u, Order $order): void
	{
		if (strcasecmp((string)$u->getUploaderEmail(), (string)$order->getBuyerEmail()) !== 0) {
			throw new NotFoundException();
		}
		$this->purge($u);
	}

	/** Takedown — row soft-delete + best-effort file purge (admin or owner). */
	public function purge(Upload $u): void
	{
		$u->setDeletedAt(new \DateTimeImmutable());
		$this->uploads->update($u);
		try {
			$dir = $this->dir();
			if ($dir->fileExists((string)$u->getFileRef())) {
				$dir->getFile((string)$u->getFileRef())->delete();
			}
		} catch (\Throwable) {
			// orphan files are janitor work, not a fatal
		}
	}

	/**
	 * NH-15 — thumbnail for image uploads. Re-encoded via GD (strips
	 * metadata + any appended payloads); returns NULL for non-images or
	 * when GD is unavailable — the row still stores, just no thumb.
	 */
	private function makeThumb(string $tmp, string $mime): ?string
	{
		if (!str_starts_with($mime, 'image/') || !extension_loaded('gd')) {
			return null;
		}
		$img = @imagecreatefromstring((string)@file_get_contents($tmp));
		if ($img === false) {
			return null;
		}
		$w = imagesx($img); $h = imagesy($img);
		$max = 320;
		$scale = min(1.0, $max / max($w, $h));
		$tw = max(1, (int)round($w * $scale));
		$th = max(1, (int)round($h * $scale));
		$dst = imagecreatetruecolor($tw, $th);
		if ($dst === false) {
			imagedestroy($img);
			return null;
		}
		imagecopyresampled($dst, $img, 0, 0, 0, 0, $tw, $th, $w, $h);
		$buf = null;
		ob_start();
		$ok = imagejpeg($dst, null, 80);
		$buf = ob_get_clean();
		imagedestroy($img);
		imagedestroy($dst);
		if (!$ok || $buf === false || $buf === '') {
			return null;
		}
		$ref = 'thumb_' . bin2hex(random_bytes(16));
		try {
			$this->dir()->newFile($ref, $buf);
			return $ref;
		} catch (\Throwable) {
			return null;
		}
	}

	/** NH-15 thumb stream — same entitlement funnel as streamFor(). */
	public function thumbFor(Upload $u, ?Order $viewerOrder = null, Offering $o = null): StreamResponse
	{
		if ($u->getThumbRef() === null) {
			throw new NotFoundException();
		}
		// entitlement check rides on the full-file path: thumbs of a file
		// the viewer may not see are refused identically
		$this->assertViewable($u, $viewerOrder, $o);
		$dir = $this->dir();
		$ref = (string)$u->getThumbRef();
		if (!$dir->fileExists($ref)) {
			throw new NotFoundException();
		}
		$resp = new StreamResponse($dir->getFile($ref)->fopen('rb'));
		$resp->addHeader('Content-Type', 'image/jpeg');
		$resp->addHeader('X-Content-Type-Options', 'nosniff');
		$resp->addHeader('Cache-Control', 'private, max-age=3600');
		return $resp;
	}

	/** Shared entitlement assertion — stream, thumb, and abuse-report gate. */
	public function assertViewable(Upload $u, ?Order $viewerOrder = null, Offering $o = null): void
	{
		if ($u->getDeletedAt() !== null) {
			throw new NotFoundException();
		}
		if ($viewerOrder === null || $o === null) {
			return; // admin path — gate is upstream
		}
		$allowed = strcasecmp((string)$u->getUploaderEmail(), (string)$viewerOrder->getBuyerEmail()) === 0;
		if (!$allowed && $this->enabledFor($o) && $viewerOrder->getStatus() === 'paid'
			&& (int)$viewerOrder->getOfferingId() === (int)$u->getOfferingId()) {
			$scope = (string)$o->getSharingScope() === 'offering' ? 'offering' : 'occurrence';
			if ($scope === 'offering') {
				$allowed = true;
			} else {
				foreach ($this->lines->findByOrder((int)$viewerOrder->getId()) as $l) {
					if ($l->getLineKind() !== 'seat') {
						continue;
					}
					$ul = $this->lines->find((int)$u->getOccurrenceId());
					if ($ul !== null && (string)$ul->getTargetType() === (string)$l->getTargetType()
						&& (int)$ul->getTargetId() === (int)$l->getTargetId()) {
						$allowed = true;
						break;
					}
				}
			}
		}
		if (!$allowed) {
			throw new NotFoundException();
		}
	}

	private function dir(): \OCP\Files\SimpleFS\ISimpleFolder
	{
		try {
			return $this->appData->getFolder('uploads');
		} catch (\Throwable) {
			return $this->appData->newFolder('uploads');
		}
	}
}

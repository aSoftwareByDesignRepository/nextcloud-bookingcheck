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
		$ref = bin2hex(random_bytes(16));
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
		$u->setMime((string)($file['type'] ?? 'application/octet-stream'));
		$u->setSha256((string)hash_file('sha256', $tmp));
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
		if ($u->getDeletedAt() !== null) {
			throw new NotFoundException();
		}
		if ($viewerOrder !== null && $o !== null) {
			$allowed = strcasecmp((string)$u->getUploaderEmail(), (string)$viewerOrder->getBuyerEmail()) === 0;
			if (!$allowed && $this->enabledFor($o) && $viewerOrder->getStatus() === 'paid'
				&& (int)$viewerOrder->getOfferingId() === (int)$u->getOfferingId()) {
				$scope = (string)$o->getSharingScope() === 'offering' ? 'offering' : 'occurrence';
				if ($scope === 'offering') {
					$allowed = true;
				} else {
					$occs = [];
					foreach ($this->lines->findByOrder((int)$viewerOrder->getId()) as $l) {
						if ($l->getLineKind() === 'seat') {
							$occs[] = ['type' => (string)$l->getTargetType(), 'id' => (int)$l->getTargetId()];
						}
					}
					$ul = $this->lines->find((int)$u->getOccurrenceId());
					if ($ul !== null) {
						foreach ($occs as $occ) {
							if ($occ['type'] === (string)$ul->getTargetType()
								&& $occ['id'] === (int)$ul->getTargetId()) {
								$allowed = true;
								break;
							}
						}
					}
				}
			}
			if (!$allowed) {
				throw new NotFoundException(); // uniform — no existence oracle
			}
		}
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

	private function dir(): \OCP\Files\SimpleFS\ISimpleFolder
	{
		try {
			return $this->appData->getFolder('uploads');
		} catch (\Throwable) {
			return $this->appData->newFolder('uploads');
		}
	}
}

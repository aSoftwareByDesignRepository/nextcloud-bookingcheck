<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the `bc_upload` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getOfferingId()
 * @method void setOfferingId(int $offeringId)
 * @method int|null getOccurrenceId()
 * @method void setOccurrenceId(?int $occurrenceId)
 * @method string getUploaderEmail()
 * @method void setUploaderEmail(string $uploaderEmail)
 * @method string getDisplayName()
 * @method void setDisplayName(string $displayName)
 * @method string getFileRef()
 * @method void setFileRef(string $fileRef)
 * @method int getSizeBytes()
 * @method void setSizeBytes(int $sizeBytes)
 * @method string getMime()
 * @method void setMime(string $mime)
 * @method string getSha256()
 * @method void setSha256(string $sha256)
 * @method string|null getThumbRef()
 * @method void setThumbRef(?string $thumbRef)
 * @method string getAvState()
 * @method void setAvState(string $avState)
 * @method \DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(\DateTimeImmutable $createdAt)
 * @method \DateTimeImmutable|null getDeletedAt()
 * @method void setDeletedAt(?\DateTimeImmutable $deletedAt)
 */
class Upload extends Entity
{
	protected $offeringId;
	protected $occurrenceId;
	protected $uploaderEmail;
	protected $displayName;
	protected $fileRef;
	protected $sizeBytes;
	protected $mime;
	protected $sha256;
	protected $thumbRef;
	protected $avState;
	protected $createdAt;
	protected $deletedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('offeringId', 'integer');
		$this->addType('occurrenceId', 'integer');
		$this->addType('sizeBytes', 'integer');
		$this->addType('createdAt', 'datetime_immutable');
		$this->addType('deletedAt', 'datetime_immutable');
		$this->addType('thumbRef', 'string');
		$this->addType('avState', 'string');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'offeringId' => (int)$this->offeringId,
			'displayName' => $this->displayName,
			'sizeBytes' => (int)$this->sizeBytes,
			'mime' => $this->mime,
			'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
			'thumbRef' => $this->thumbRef,
			'avState' => $this->avState,
		];
	}
}

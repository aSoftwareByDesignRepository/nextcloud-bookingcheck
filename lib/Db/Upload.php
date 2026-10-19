<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

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
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'offeringId' => (int)$this->offeringId,
			'occurrenceId' => $this->occurrenceId === null ? null : (int)$this->occurrenceId,
			'uploaderEmail' => $this->uploaderEmail,
			'displayName' => $this->displayName,
			'fileRef' => $this->fileRef,
			'sizeBytes' => (int)$this->sizeBytes,
			'mime' => $this->mime,
			'sha256' => $this->sha256,
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
			'deletedAt' => $this->deletedAt === null ? null : $this->deletedAt->format(\DateTimeInterface::ATOM),
		];
	}
}

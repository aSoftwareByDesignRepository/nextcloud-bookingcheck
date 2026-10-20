<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Attendee upload (UGC). occurrence_id stores the uploader's seat
 * order-line id — the line pins target_type+target_id+order uniquely,
 * so scope checks never collide across event/slot/series ids.
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

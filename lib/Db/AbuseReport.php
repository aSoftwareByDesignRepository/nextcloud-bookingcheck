<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

class AbuseReport extends Entity
{
	protected $uploadId;
	protected $reporter;
	protected $reason;
	protected $status;
	protected $createdAt;
	protected $resolvedAt;
	protected $resolvedBy;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('uploadId', 'integer');
		$this->addType('createdAt', 'datetime_immutable');
		$this->addType('resolvedAt', 'datetime_immutable');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'uploadId' => (int)$this->uploadId,
			'reporter' => $this->reporter,
			'reason' => $this->reason,
			'status' => $this->status,
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
			'resolvedAt' => $this->resolvedAt === null ? null : $this->resolvedAt->format(\DateTimeInterface::ATOM),
			'resolvedBy' => $this->resolvedBy,
		];
	}
}

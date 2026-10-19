<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

class EmailOutbox extends Entity
{
	protected $kind;
	protected $toEmail;
	protected $locale;
	protected $payloadJson;
	protected $status;
	protected $attempts;
	protected $nextAt;
	protected $createdAt;
	protected $sentAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('attempts', 'integer');
		$this->addType('nextAt', 'datetime_immutable');
		$this->addType('createdAt', 'datetime_immutable');
		$this->addType('sentAt', 'datetime_immutable');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'kind' => $this->kind,
			'toEmail' => $this->toEmail,
			'locale' => $this->locale,
			'payloadJson' => $this->payloadJson,
			'status' => $this->status,
			'attempts' => (int)$this->attempts,
			'nextAt' => $this->nextAt === null ? null : $this->nextAt->format(\DateTimeInterface::ATOM),
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
			'sentAt' => $this->sentAt === null ? null : $this->sentAt->format(\DateTimeInterface::ATOM),
		];
	}
}

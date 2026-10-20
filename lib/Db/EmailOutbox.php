<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the `bc_email` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getToEmail()
 * @method void setToEmail(string $toEmail)
 * @method string getLocale()
 * @method void setLocale(string $locale)
 * @method string getPayloadJson()
 * @method void setPayloadJson(string $payloadJson)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method int getAttempts()
 * @method void setAttempts(int $attempts)
 * @method \DateTimeImmutable|null getNextAt()
 * @method void setNextAt(?\DateTimeImmutable $nextAt)
 * @method \DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(\DateTimeImmutable $createdAt)
 * @method \DateTimeImmutable|null getSentAt()
 * @method void setSentAt(?\DateTimeImmutable $sentAt)
 */
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

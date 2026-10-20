<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the `bc_webhook` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUrl()
 * @method void setUrl(string $url)
 * @method string getSecretEnc()
 * @method void setSecretEnc(string $secretEnc)
 * @method string|null getSecretPrevEnc()
 * @method void setSecretPrevEnc(?string $secretPrevEnc)
 * @method \DateTimeImmutable|null getSecretRotatedAt()
 * @method void setSecretRotatedAt(?\DateTimeImmutable $secretRotatedAt)
 * @method string getEventsJson()
 * @method void setEventsJson(string $eventsJson)
 * @method int getActive()
 * @method void setActive(int $active)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method \DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(\DateTimeImmutable $createdAt)
 */
class Webhook extends Entity
{
	protected $url;
	protected $secretEnc;
	protected $secretPrevEnc;
	protected $secretRotatedAt;
	protected $eventsJson;
	protected $active;
	protected $createdBy;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('secretRotatedAt', 'datetime_immutable');
		$this->addType('active', 'integer');
		$this->addType('createdAt', 'datetime_immutable');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'url' => $this->url,
			'secretEnc' => $this->secretEnc,
			'secretPrevEnc' => $this->secretPrevEnc,
			'secretRotatedAt' => $this->secretRotatedAt === null ? null : $this->secretRotatedAt->format(\DateTimeInterface::ATOM),
			'eventsJson' => $this->eventsJson,
			'active' => (int)$this->active,
			'createdBy' => $this->createdBy,
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
		];
	}
}

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the `bc_broadcast` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getOfferingId()
 * @method void setOfferingId(int $offeringId)
 * @method string getScope()
 * @method void setScope(string $scope)
 * @method string getSubject()
 * @method void setSubject(string $subject)
 * @method string getBodyMd()
 * @method void setBodyMd(string $bodyMd)
 * @method string|null getLocaleMap()
 * @method void setLocaleMap(?string $localeMap)
 * @method string getSentBy()
 * @method void setSentBy(string $sentBy)
 * @method \DateTimeImmutable getSentAt()
 * @method void setSentAt(\DateTimeImmutable $sentAt)
 * @method int getRcptCount()
 * @method void setRcptCount(int $rcptCount)
 */
class Broadcast extends Entity
{
	protected $offeringId;
	protected $scope;
	protected $subject;
	protected $bodyMd;
	protected $localeMap;
	protected $sentBy;
	protected $sentAt;
	protected $rcptCount;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('offeringId', 'integer');
		$this->addType('sentAt', 'datetime_immutable');
		$this->addType('rcptCount', 'integer');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'offeringId' => (int)$this->offeringId,
			'scope' => $this->scope,
			'subject' => $this->subject,
			'bodyMd' => $this->bodyMd,
			'localeMap' => $this->localeMap,
			'sentBy' => $this->sentBy,
			'sentAt' => $this->sentAt === null ? null : $this->sentAt->format(\DateTimeInterface::ATOM),
			'rcptCount' => (int)$this->rcptCount,
		];
	}
}

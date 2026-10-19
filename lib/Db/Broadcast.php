<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

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

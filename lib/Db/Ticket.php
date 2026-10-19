<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

class Ticket extends Entity
{
	protected $lineId;
	protected $seatNo;
	protected $attendeeName;
	protected $attendeeEmail;
	protected $resourceName;
	protected $qrTokenHash;
	protected $qrTokenEnc;
	protected $checkedInAt;
	protected $checkedInBy;
	protected $passAppleRef;
	protected $passGoogleRef;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('lineId', 'integer');
		$this->addType('seatNo', 'integer');
		$this->addType('checkedInAt', 'datetime_immutable');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'lineId' => (int)$this->lineId,
			'seatNo' => $this->seatNo === null ? null : (int)$this->seatNo,
			'attendeeName' => $this->attendeeName,
			'attendeeEmail' => $this->attendeeEmail,
			'resourceName' => $this->resourceName,
			'qrTokenHash' => $this->qrTokenHash,
			'checkedInAt' => $this->checkedInAt === null ? null : $this->checkedInAt->format(\DateTimeInterface::ATOM),
			'checkedInBy' => $this->checkedInBy,
			'passAppleRef' => $this->passAppleRef,
			'passGoogleRef' => $this->passGoogleRef,
		];
	}
}

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the `bc_ticket` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getLineId()
 * @method void setLineId(int $lineId)
 * @method int|null getSeatNo()
 * @method void setSeatNo(?int $seatNo)
 * @method string|null getAttendeeName()
 * @method void setAttendeeName(?string $attendeeName)
 * @method string|null getAttendeeEmail()
 * @method void setAttendeeEmail(?string $attendeeEmail)
 * @method string|null getResourceName()
 * @method void setResourceName(?string $resourceName)
 * @method string getQrTokenHash()
 * @method void setQrTokenHash(string $qrTokenHash)
 * @method string|null getQrTokenEnc()
 * @method void setQrTokenEnc(?string $qrTokenEnc)
 * @method \DateTimeImmutable|null getCheckedInAt()
 * @method void setCheckedInAt(?\DateTimeImmutable $checkedInAt)
 * @method string|null getCheckedInBy()
 * @method void setCheckedInBy(?string $checkedInBy)
 * @method \DateTimeImmutable|null getRevokedAt()
 * @method void setRevokedAt(?\DateTimeImmutable $revokedAt)
 * @method string|null getPassAppleRef()
 * @method void setPassAppleRef(?string $passAppleRef)
 * @method string|null getPassGoogleRef()
 * @method void setPassGoogleRef(?string $passGoogleRef)
 */
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
	protected $revokedAt;
	protected $passAppleRef;
	protected $passGoogleRef;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('lineId', 'integer');
		$this->addType('seatNo', 'integer');
		$this->addType('checkedInAt', 'datetime_immutable');
		$this->addType('revokedAt', 'datetime_immutable');
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
			'revokedAt' => $this->revokedAt === null ? null : $this->revokedAt->format(\DateTimeInterface::ATOM),
			'passAppleRef' => $this->passAppleRef,
			'passGoogleRef' => $this->passGoogleRef,
		];
	}
}

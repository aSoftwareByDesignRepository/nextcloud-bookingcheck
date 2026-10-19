<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getKind()
 * @method void setKind(string $v)
 * @method string getPayloadJson()
 * @method void setPayloadJson(string $v)
 * @method string getStatus()
 * @method void setStatus(string $v)
 * @method int getAttempts()
 * @method void setAttempts(int $v)
 * @method \DateTimeImmutable getNextAt()
 * @method void setNextAt(\DateTimeInterface $v)
 * @method \DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(\DateTimeInterface $v)
 * @method ?\DateTimeImmutable getDoneAt()
 * @method void setDoneAt(?\DateTimeInterface $v)
 */
class SyncTask extends Entity
{
	protected $kind;
	protected $payloadJson;
	protected $status;
	protected $attempts;
	protected $nextAt;
	protected $createdAt;
	protected $doneAt;

	public function __construct()
	{
		$this->addType('kind', 'string');
		$this->addType('payloadJson', 'string');
		$this->addType('status', 'string');
		$this->addType('attempts', 'integer');
		$this->addType('nextAt', 'datetime_immutable');
		$this->addType('createdAt', 'datetime_immutable');
		$this->addType('doneAt', 'datetime_immutable');
	}
}

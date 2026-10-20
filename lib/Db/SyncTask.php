<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the `bc_sync_task` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getPayloadJson()
 * @method void setPayloadJson(string $payloadJson)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method int getAttempts()
 * @method void setAttempts(int $attempts)
 * @method \DateTimeImmutable getNextAt()
 * @method void setNextAt(\DateTimeImmutable $nextAt)
 * @method \DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(\DateTimeImmutable $createdAt)
 * @method \DateTimeImmutable|null getDoneAt()
 * @method void setDoneAt(?\DateTimeImmutable $doneAt)
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

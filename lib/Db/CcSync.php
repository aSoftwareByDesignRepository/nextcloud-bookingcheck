<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

class CcSync extends Entity
{
	protected $orderId;
	protected $remoteRef;
	protected $status;
	protected $attempts;
	protected $syncedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('orderId', 'integer');
		$this->addType('attempts', 'integer');
		$this->addType('syncedAt', 'datetime_immutable');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'orderId' => (int)$this->orderId,
			'remoteRef' => $this->remoteRef,
			'status' => $this->status,
			'attempts' => (int)$this->attempts,
			'syncedAt' => $this->syncedAt === null ? null : $this->syncedAt->format(\DateTimeInterface::ATOM),
		];
	}
}

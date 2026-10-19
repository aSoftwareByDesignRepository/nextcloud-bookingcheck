<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

class Payment extends Entity
{
	protected $orderId;
	protected $provider;
	protected $pspRef;
	protected $pspEventId;
	protected $status;
	protected $disputed;
	protected $amountCents;
	protected $rawStatus;
	protected $refundRef;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('orderId', 'integer');
		$this->addType('disputed', 'integer');
		$this->addType('amountCents', 'integer');
		$this->addType('createdAt', 'datetime_immutable');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'orderId' => (int)$this->orderId,
			'provider' => $this->provider,
			'pspRef' => $this->pspRef,
			'pspEventId' => $this->pspEventId,
			'status' => $this->status,
			'disputed' => (int)$this->disputed,
			'amountCents' => (int)$this->amountCents,
			'rawStatus' => $this->rawStatus,
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
		];
	}
}

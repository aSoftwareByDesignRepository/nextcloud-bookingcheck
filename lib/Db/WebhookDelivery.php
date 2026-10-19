<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

class WebhookDelivery extends Entity
{
	protected $webhookId;
	protected $event;
	protected $payloadJson;
	protected $status;
	protected $attempts;
	protected $nextRetryAt;
	protected $deliveredAt;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('webhookId', 'integer');
		$this->addType('attempts', 'integer');
		$this->addType('nextRetryAt', 'datetime_immutable');
		$this->addType('deliveredAt', 'datetime_immutable');
		$this->addType('createdAt', 'datetime_immutable');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'webhookId' => (int)$this->webhookId,
			'event' => $this->event,
			'payloadJson' => $this->payloadJson,
			'status' => $this->status,
			'attempts' => (int)$this->attempts,
			'nextRetryAt' => $this->nextRetryAt === null ? null : $this->nextRetryAt->format(\DateTimeInterface::ATOM),
			'deliveredAt' => $this->deliveredAt === null ? null : $this->deliveredAt->format(\DateTimeInterface::ATOM),
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
		];
	}
}

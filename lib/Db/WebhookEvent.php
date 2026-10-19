<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

class WebhookEvent extends Entity
{
	protected $provider;
	protected $eventId;
	protected $receivedAt;
	protected $processedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('receivedAt', 'datetime_immutable');
		$this->addType('processedAt', 'datetime_immutable');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'provider' => $this->provider,
			'eventId' => $this->eventId,
			'receivedAt' => $this->receivedAt === null ? null : $this->receivedAt->format(\DateTimeInterface::ATOM),
			'processedAt' => $this->processedAt === null ? null : $this->processedAt->format(\DateTimeInterface::ATOM),
		];
	}
}

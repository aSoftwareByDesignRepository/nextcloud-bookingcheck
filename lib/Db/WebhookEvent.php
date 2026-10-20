<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the `bc_webhook_evt` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getProvider()
 * @method void setProvider(string $provider)
 * @method string getEventId()
 * @method void setEventId(string $eventId)
 * @method \DateTimeImmutable getReceivedAt()
 * @method void setReceivedAt(\DateTimeImmutable $receivedAt)
 * @method \DateTimeImmutable|null getProcessedAt()
 * @method void setProcessedAt(?\DateTimeImmutable $processedAt)
 */
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

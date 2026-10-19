<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

class Review extends Entity
{
	protected $lineId;
	protected $rating;
	protected $text;
	protected $status;
	protected $createdAt;
	protected $publishedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('lineId', 'integer');
		$this->addType('rating', 'integer');
		$this->addType('createdAt', 'datetime_immutable');
		$this->addType('publishedAt', 'datetime_immutable');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'lineId' => (int)$this->lineId,
			'rating' => (int)$this->rating,
			'text' => $this->text,
			'status' => $this->status,
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
			'publishedAt' => $this->publishedAt === null ? null : $this->publishedAt->format(\DateTimeInterface::ATOM),
		];
	}
}

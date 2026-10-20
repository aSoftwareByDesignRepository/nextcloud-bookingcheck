<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the `bc_review` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getLineId()
 * @method void setLineId(int $lineId)
 * @method int getRating()
 * @method void setRating(int $rating)
 * @method string|null getText()
 * @method void setText(?string $text)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method \DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(\DateTimeImmutable $createdAt)
 * @method \DateTimeImmutable|null getPublishedAt()
 * @method void setPublishedAt(?\DateTimeImmutable $publishedAt)
 */
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

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

class Answer extends Entity
{
	protected $ticketId;
	protected $questionId;
	protected $value;
	protected $answeredAt;
	protected $wordingRev;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('ticketId', 'integer');
		$this->addType('questionId', 'integer');
		$this->addType('answeredAt', 'datetime_immutable');
		$this->addType('wordingRev', 'integer');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'ticketId' => (int)$this->ticketId,
			'questionId' => (int)$this->questionId,
			'value' => $this->value,
			'answeredAt' => $this->answeredAt === null ? null : $this->answeredAt->format(\DateTimeInterface::ATOM),
			'wordingRev' => (int)$this->wordingRev,
		];
	}
}

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

class InvoiceSeq extends Entity
{
	protected $prefix;
	protected $year;
	protected $next;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('year', 'integer');
		$this->addType('next', 'integer');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'prefix' => $this->prefix,
			'year' => (int)$this->year,
			'next' => (int)$this->next,
		];
	}
}

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

class Invoice extends Entity
{
	protected $orderId;
	protected $number;
	protected $prefix;
	protected $year;
	protected $type;
	protected $creditsInvoiceId;
	protected $netCents;
	protected $vatCents;
	protected $grossCents;
	protected $pdfHash;
	protected $issuedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('orderId', 'integer');
		$this->addType('number', 'integer');
		$this->addType('year', 'integer');
		$this->addType('creditsInvoiceId', 'integer');
		$this->addType('netCents', 'integer');
		$this->addType('vatCents', 'integer');
		$this->addType('grossCents', 'integer');
		$this->addType('issuedAt', 'datetime_immutable');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'orderId' => (int)$this->orderId,
			'number' => (int)$this->number,
			'prefix' => $this->prefix,
			'year' => (int)$this->year,
			'type' => $this->type,
			'creditsInvoiceId' => $this->creditsInvoiceId === null ? null : (int)$this->creditsInvoiceId,
			'netCents' => (int)$this->netCents,
			'vatCents' => (int)$this->vatCents,
			'grossCents' => (int)$this->grossCents,
			'pdfHash' => $this->pdfHash,
			'issuedAt' => $this->issuedAt === null ? null : $this->issuedAt->format(\DateTimeInterface::ATOM),
		];
	}
}

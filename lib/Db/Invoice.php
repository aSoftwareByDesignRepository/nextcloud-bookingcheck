<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the `bc_invoice` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getOrderId()
 * @method void setOrderId(int $orderId)
 * @method int getNumber()
 * @method void setNumber(int $number)
 * @method string getPrefix()
 * @method void setPrefix(string $prefix)
 * @method int getYear()
 * @method void setYear(int $year)
 * @method string getType()
 * @method void setType(string $type)
 * @method int|null getCreditsInvoiceId()
 * @method void setCreditsInvoiceId(?int $creditsInvoiceId)
 * @method int getNetCents()
 * @method void setNetCents(int $netCents)
 * @method int getVatCents()
 * @method void setVatCents(int $vatCents)
 * @method int getGrossCents()
 * @method void setGrossCents(int $grossCents)
 * @method string|null getPdfHash()
 * @method void setPdfHash(?string $pdfHash)
 * @method \DateTimeImmutable getIssuedAt()
 * @method void setIssuedAt(\DateTimeImmutable $issuedAt)
 */
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

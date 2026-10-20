<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the `bc_payment` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getOrderId()
 * @method void setOrderId(int $orderId)
 * @method string getProvider()
 * @method void setProvider(string $provider)
 * @method string|null getPspRef()
 * @method void setPspRef(?string $pspRef)
 * @method string|null getPspEventId()
 * @method void setPspEventId(?string $pspEventId)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method int getDisputed()
 * @method void setDisputed(int $disputed)
 * @method int getAmountCents()
 * @method void setAmountCents(int $amountCents)
 * @method string|null getRawStatus()
 * @method void setRawStatus(?string $rawStatus)
 * @method string|null getRefundRef()
 * @method void setRefundRef(?string $refundRef)
 * @method \DateTimeImmutable|null getRefundClaimedAt()
 * @method void setRefundClaimedAt(?\DateTimeImmutable $refundClaimedAt)
 * @method \DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(\DateTimeImmutable $createdAt)
 */
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
	protected $refundClaimedAt;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('orderId', 'integer');
		$this->addType('disputed', 'integer');
		$this->addType('amountCents', 'integer');
		$this->addType('createdAt', 'datetime_immutable');
		$this->addType('refundClaimedAt', 'datetime_immutable');
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

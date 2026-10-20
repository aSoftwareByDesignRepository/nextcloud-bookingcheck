<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

class Order extends Entity
{
	protected $ref;
	protected $offeringId;
	protected $buyerName;
	protected $buyerEmail;
	protected $buyerLocale;
	protected $buyerCompany;
	protected $billingCountry;
	protected $vatId;
	protected $viesStatus;
	protected $reverseCharge;
	protected $amountCents;
	protected $currency;
	protected $status;
	protected $idemToken;
	protected $manageTokenHash;
	protected $isTest;
	protected $voucherId;
	protected $discountCents;
	protected $promoId;
	protected $promoCode;
	protected $promoCents;
	protected $approvedBy;
	protected $approvedAt;
	protected $consentAt;
	protected $consentText;
	protected $createdAt;
	protected $paidAt;
	protected $expiresAt;
	protected $staffNote;
	protected $payMethod;
	protected $remainderCents;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('offeringId', 'integer');
		$this->addType('reverseCharge', 'integer');
		$this->addType('amountCents', 'integer');
		$this->addType('isTest', 'integer');
		$this->addType('voucherId', 'integer');
		$this->addType('discountCents', 'integer');
		$this->addType('promoId', 'integer');
		$this->addType('promoCents', 'integer');
		$this->addType('approvedAt', 'datetime_immutable');
		$this->addType('consentAt', 'datetime_immutable');
		$this->addType('payMethod', 'string');
		$this->addType('remainderCents', 'integer');
		$this->addType('createdAt', 'datetime_immutable');
		$this->addType('paidAt', 'datetime_immutable');
		$this->addType('expiresAt', 'datetime_immutable');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'ref' => $this->ref,
			'offeringId' => (int)$this->offeringId,
			'buyerName' => $this->buyerName,
			'buyerEmail' => $this->buyerEmail,
			'buyerLocale' => $this->buyerLocale,
			'buyerCompany' => $this->buyerCompany,
			'billingCountry' => $this->billingCountry,
			'vatId' => $this->vatId,
			'viesStatus' => $this->viesStatus,
			'reverseCharge' => (int)$this->reverseCharge,
			'amountCents' => (int)$this->amountCents,
			'currency' => $this->currency,
			'status' => $this->status,
			'isTest' => (int)$this->isTest,
			'staffNote' => $this->staffNote,
			'voucherId' => $this->voucherId === null ? null : (int)$this->voucherId,
			'discountCents' => (int)$this->discountCents,
			'promoId' => $this->promoId === null ? null : (int)$this->promoId,
			'promoCode' => $this->promoCode,
			'promoCents' => (int)$this->promoCents,
			'payMethod' => $this->payMethod,
			'remainderCents' => (int)$this->remainderCents,
			'approvedBy' => $this->approvedBy,
			'approvedAt' => $this->approvedAt === null ? null : $this->approvedAt->format(\DateTimeInterface::ATOM),
			'consentAt' => $this->consentAt === null ? null : $this->consentAt->format(\DateTimeInterface::ATOM),
			'consentText' => $this->consentText,
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
			'paidAt' => $this->paidAt === null ? null : $this->paidAt->format(\DateTimeInterface::ATOM),
			'expiresAt' => $this->expiresAt === null ? null : $this->expiresAt->format(\DateTimeInterface::ATOM),
		];
	}
}

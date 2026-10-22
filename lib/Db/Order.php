<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the `bc_order` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getRef()
 * @method void setRef(string $ref)
 * @method int getOfferingId()
 * @method void setOfferingId(int $offeringId)
 * @method string getBuyerName()
 * @method void setBuyerName(string $buyerName)
 * @method string getBuyerEmail()
 * @method void setBuyerEmail(string $buyerEmail)
 * @method string getBuyerLocale()
 * @method void setBuyerLocale(string $buyerLocale)
 * @method string|null getBuyerCompany()
 * @method void setBuyerCompany(?string $buyerCompany)
 * @method string|null getBillingCountry()
 * @method void setBillingCountry(?string $billingCountry)
 * @method string|null getVatId()
 * @method void setVatId(?string $vatId)
 * @method string getViesStatus()
 * @method void setViesStatus(string $viesStatus)
 * @method int getReverseCharge()
 * @method void setReverseCharge(int $reverseCharge)
 * @method int getAmountCents()
 * @method void setAmountCents(int $amountCents)
 * @method string getCurrency()
 * @method void setCurrency(string $currency)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string getIdemToken()
 * @method void setIdemToken(string $idemToken)
 * @method string getManageTokenHash()
 * @method void setManageTokenHash(string $manageTokenHash)
 * @method int getIsTest()
 * @method void setIsTest(int $isTest)
 * @method int|null getVoucherId()
 * @method void setVoucherId(?int $voucherId)
 * @method int getDiscountCents()
 * @method void setDiscountCents(int $discountCents)
 * @method int|null getPromoId()
 * @method void setPromoId(?int $promoId)
 * @method string|null getPromoCode()
 * @method void setPromoCode(?string $promoCode)
 * @method int getPromoCents()
 * @method void setPromoCents(int $promoCents)
 * @method string|null getApprovedBy()
 * @method void setApprovedBy(?string $approvedBy)
 * @method \DateTimeImmutable|null getApprovedAt()
 * @method void setApprovedAt(?\DateTimeImmutable $approvedAt)
 * @method \DateTimeImmutable|null getConsentAt()
 * @method void setConsentAt(?\DateTimeImmutable $consentAt)
 * @method string|null getConsentText()
 * @method void setConsentText(?string $consentText)
 * @method \DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(\DateTimeImmutable $createdAt)
 * @method \DateTimeImmutable|null getPaidAt()
 * @method void setPaidAt(?\DateTimeImmutable $paidAt)
 * @method \DateTimeImmutable|null getExpiresAt()
 * @method void setExpiresAt(?\DateTimeImmutable $expiresAt)
 * @method string|null getStaffNote()
 * @method void setStaffNote(?string $staffNote)
 * @method string getPayMethod()
 * @method void setPayMethod(string $payMethod)
 * @method int getRemainderCents()
 * @method void setRemainderCents(int $remainderCents)
 * @method int getVatBp()
 * @method void setVatBp(int $vatBp)
 */
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
	protected $vatBp;

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
		$this->addType('vatBp', 'integer');
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
			'vatBp' => (int)$this->vatBp,
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

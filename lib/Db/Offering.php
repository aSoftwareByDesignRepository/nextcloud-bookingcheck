<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the `bc_offering` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getType()
 * @method void setType(string $type)
 * @method string getSlug()
 * @method void setSlug(string $slug)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getDescr()
 * @method void setDescr(?string $descr)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method int getPriceCents()
 * @method void setPriceCents(int $priceCents)
 * @method string getCurrency()
 * @method void setCurrency(string $currency)
 * @method int getVatBp()
 * @method void setVatBp(int $vatBp)
 * @method string|null getCancelPolicy()
 * @method void setCancelPolicy(?string $cancelPolicy)
 * @method int getBookSeries()
 * @method void setBookSeries(int $bookSeries)
 * @method int|null getDeckBoardId()
 * @method void setDeckBoardId(?int $deckBoardId)
 * @method string|null getTalkRoom()
 * @method void setTalkRoom(?string $talkRoom)
 * @method int getApprovalRequired()
 * @method void setApprovalRequired(int $approvalRequired)
 * @method int|null getApprovalHoldCap()
 * @method void setApprovalHoldCap(?int $approvalHoldCap)
 * @method string getWaitlistMode()
 * @method void setWaitlistMode(string $waitlistMode)
 * @method int getReviewDisplay()
 * @method void setReviewDisplay(int $reviewDisplay)
 * @method string|null getLogoFileRef()
 * @method void setLogoFileRef(?string $logoFileRef)
 * @method string|null getAccentHex()
 * @method void setAccentHex(?string $accentHex)
 * @method int getSharingEnabled()
 * @method void setSharingEnabled(int $sharingEnabled)
 * @method string getSharingScope()
 * @method void setSharingScope(string $sharingScope)
 * @method int getUploadMaxMb()
 * @method void setUploadMaxMb(int $uploadMaxMb)
 * @method int getUploadQuotaMb()
 * @method void setUploadQuotaMb(int $uploadQuotaMb)
 * @method string|null getEmbedDomains()
 * @method void setEmbedDomains(?string $embedDomains)
 * @method \DateTimeImmutable|null getSalesFrom()
 * @method void setSalesFrom(?\DateTimeImmutable $salesFrom)
 * @method \DateTimeImmutable|null getSalesTo()
 * @method void setSalesTo(?\DateTimeImmutable $salesTo)
 * @method string|null getTosText()
 * @method void setTosText(?string $tosText)
 * @method int getListInDirectory()
 * @method void setListInDirectory(int $listInDirectory)
 * @method string getEolState()
 * @method void setEolState(string $eolState)
 * @method string|null getEolPolicy()
 * @method void setEolPolicy(?string $eolPolicy)
 * @method \DateTimeImmutable|null getEolActionAt()
 * @method void setEolActionAt(?\DateTimeImmutable $eolActionAt)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method \DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(\DateTimeImmutable $createdAt)
 * @method \DateTimeImmutable getUpdatedAt()
 * @method void setUpdatedAt(\DateTimeImmutable $updatedAt)
 * @method int getReminderDays()
 * @method void setReminderDays(int $reminderDays)
 * @method int getDynBp()
 * @method void setDynBp(int $dynBp)
 * @method int getDynStepPct()
 * @method void setDynStepPct(int $dynStepPct)
 * @method int getDepositBp()
 * @method void setDepositBp(int $depositBp)
 * @method int getInvoicePay()
 * @method void setInvoicePay(int $invoicePay)
 * @method string|null getPassScope()
 * @method void setPassScope(?string $passScope)
 */
class Offering extends Entity
{
	protected $type;
	protected $slug;
	protected $title;
	protected $descr;
	protected $status;
	protected $priceCents;
	protected $currency;
	protected $vatBp;
	protected $cancelPolicy;
	protected $bookSeries;
	protected $deckBoardId;
	protected $talkRoom;
	protected $approvalRequired;
	protected $approvalHoldCap;
	protected $waitlistMode;
	protected $reviewDisplay;
	protected $logoFileRef;
	protected $accentHex;
	protected $sharingEnabled;
	protected $sharingScope;
	protected $uploadMaxMb;
	protected $uploadQuotaMb;
	protected $embedDomains;
	protected $salesFrom;
	protected $salesTo;
	protected $tosText;
	protected $listInDirectory;
	protected $eolState;
	protected $eolPolicy;
	protected $eolActionAt;
	protected $createdBy;
	protected $createdAt;
	protected $updatedAt;
	protected $reminderDays;
	protected $dynBp;
	protected $dynStepPct;
	protected $depositBp;
	protected $invoicePay;
	protected $passScope;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('priceCents', 'integer');
		$this->addType('vatBp', 'integer');
		$this->addType('bookSeries', 'integer');
		$this->addType('deckBoardId', 'integer');
		$this->addType('approvalRequired', 'integer');
		$this->addType('approvalHoldCap', 'integer');
		$this->addType('reviewDisplay', 'integer');
		$this->addType('sharingEnabled', 'integer');
		$this->addType('uploadMaxMb', 'integer');
		$this->addType('uploadQuotaMb', 'integer');
		$this->addType('salesFrom', 'datetime_immutable');
		$this->addType('salesTo', 'datetime_immutable');
		$this->addType('listInDirectory', 'integer');
		$this->addType('eolActionAt', 'datetime_immutable');
		$this->addType('createdAt', 'datetime_immutable');
		$this->addType('updatedAt', 'datetime_immutable');
		$this->addType('reminderDays', 'integer');
		$this->addType('dynBp', 'integer');
		$this->addType('dynStepPct', 'integer');
		$this->addType('depositBp', 'integer');
		$this->addType('invoicePay', 'integer');
		$this->addType('passScope', 'string');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'type' => $this->type,
			'slug' => $this->slug,
			'title' => $this->title,
			'descr' => $this->descr,
			'status' => $this->status,
			'priceCents' => (int)$this->priceCents,
			'currency' => $this->currency,
			'vatBp' => (int)$this->vatBp,
			'cancelPolicy' => $this->cancelPolicy,
			'bookSeries' => (int)$this->bookSeries,
			'deckBoardId' => $this->deckBoardId === null ? null : (int)$this->deckBoardId,
			'talkRoom' => $this->talkRoom,
			'approvalRequired' => (int)$this->approvalRequired,
			'approvalHoldCap' => $this->approvalHoldCap === null ? null : (int)$this->approvalHoldCap,
			'waitlistMode' => $this->waitlistMode,
			'reviewDisplay' => (int)$this->reviewDisplay,
			'logoFileRef' => $this->logoFileRef,
			'accentHex' => $this->accentHex,
			'sharingEnabled' => (int)$this->sharingEnabled,
			'sharingScope' => $this->sharingScope,
			'uploadMaxMb' => (int)$this->uploadMaxMb,
			'uploadQuotaMb' => (int)$this->uploadQuotaMb,
			'embedDomains' => $this->embedDomains,
			'salesFrom' => $this->salesFrom === null ? null : $this->salesFrom->format(\DateTimeInterface::ATOM),
			'salesTo' => $this->salesTo === null ? null : $this->salesTo->format(\DateTimeInterface::ATOM),
			'tosText' => $this->tosText,
			'reminderDays' => (int)$this->reminderDays,
			'dynBp' => (int)$this->dynBp,
			'dynStepPct' => (int)$this->dynStepPct,
			'depositBp' => (int)$this->depositBp,
			'invoicePay' => (int)$this->invoicePay,
			'passScope' => $this->passScope,
			'listInDirectory' => (int)$this->listInDirectory,
			'eolState' => $this->eolState,
			'eolPolicy' => $this->eolPolicy,
			'eolActionAt' => $this->eolActionAt === null ? null : $this->eolActionAt->format(\DateTimeInterface::ATOM),
			'createdBy' => $this->createdBy,
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
			'updatedAt' => $this->updatedAt === null ? null : $this->updatedAt->format(\DateTimeInterface::ATOM),
		];
	}
}

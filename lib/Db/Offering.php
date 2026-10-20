<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

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

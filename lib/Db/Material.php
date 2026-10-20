<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the `bc_material` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getOfferingId()
 * @method void setOfferingId(int $offeringId)
 * @method string|null getTargetType()
 * @method void setTargetType(?string $targetType)
 * @method int|null getTargetId()
 * @method void setTargetId(?int $targetId)
 * @method int|null getLibraryId()
 * @method void setLibraryId(?int $libraryId)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method int getSort()
 * @method void setSort(int $sort)
 * @method string|null getFileRef()
 * @method void setFileRef(?string $fileRef)
 * @method string|null getPageMd()
 * @method void setPageMd(?string $pageMd)
 * @method \DateTimeImmutable|null getAvailFrom()
 * @method void setAvailFrom(?\DateTimeImmutable $availFrom)
 * @method \DateTimeImmutable|null getAvailTo()
 * @method void setAvailTo(?\DateTimeImmutable $availTo)
 */
class Material extends Entity
{
	protected $offeringId;
	protected $targetType;
	protected $targetId;
	protected $libraryId;
	protected $kind;
	protected $title;
	protected $sort;
	protected $fileRef;
	protected $pageMd;
	protected $availFrom;
	protected $availTo;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('offeringId', 'integer');
		$this->addType('targetId', 'integer');
		$this->addType('libraryId', 'integer');
		$this->addType('sort', 'integer');
		$this->addType('availFrom', 'datetime_immutable');
		$this->addType('availTo', 'datetime_immutable');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'offeringId' => (int)$this->offeringId,
			'targetType' => $this->targetType,
			'targetId' => $this->targetId === null ? null : (int)$this->targetId,
			'libraryId' => $this->libraryId === null ? null : (int)$this->libraryId,
			'kind' => $this->kind,
			'title' => $this->title,
			'sort' => (int)$this->sort,
			'fileRef' => $this->fileRef,
			'pageMd' => $this->pageMd,
			'availFrom' => $this->availFrom === null ? null : $this->availFrom->format(\DateTimeInterface::ATOM),
			'availTo' => $this->availTo === null ? null : $this->availTo->format(\DateTimeInterface::ATOM),
		];
	}
}

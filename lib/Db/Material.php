<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

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

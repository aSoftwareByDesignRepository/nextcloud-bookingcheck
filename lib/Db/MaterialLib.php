<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the `bc_material_lib` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string|null getFileRef()
 * @method void setFileRef(?string $fileRef)
 * @method string|null getPageMd()
 * @method void setPageMd(?string $pageMd)
 * @method int getAutoAttach()
 * @method void setAutoAttach(int $autoAttach)
 * @method \DateTimeImmutable getUpdatedAt()
 * @method void setUpdatedAt(\DateTimeImmutable $updatedAt)
 */
class MaterialLib extends Entity
{
	protected $title;
	protected $kind;
	protected $fileRef;
	protected $pageMd;
	protected $autoAttach;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('autoAttach', 'integer');
		$this->addType('updatedAt', 'datetime_immutable');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'title' => $this->title,
			'kind' => $this->kind,
			'fileRef' => $this->fileRef,
			'pageMd' => $this->pageMd,
			'autoAttach' => (int)$this->autoAttach,
			'updatedAt' => $this->updatedAt === null ? null : $this->updatedAt->format(\DateTimeInterface::ATOM),
		];
	}
}

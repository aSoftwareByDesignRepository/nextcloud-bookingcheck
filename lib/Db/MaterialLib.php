<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

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

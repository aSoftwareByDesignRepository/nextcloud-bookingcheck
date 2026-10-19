<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

class Question extends Entity
{
	protected $offeringId;
	protected $label;
	protected $kind;
	protected $optionsJson;
	protected $required;
	protected $sort;
	protected $visibleTo;
	protected $wordingRev;
	protected $active;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('offeringId', 'integer');
		$this->addType('required', 'integer');
		$this->addType('sort', 'integer');
		$this->addType('wordingRev', 'integer');
		$this->addType('active', 'integer');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'offeringId' => (int)$this->offeringId,
			'label' => $this->label,
			'kind' => $this->kind,
			'optionsJson' => $this->optionsJson,
			'required' => (int)$this->required,
			'sort' => (int)$this->sort,
			'visibleTo' => $this->visibleTo,
			'wordingRev' => (int)$this->wordingRev,
			'active' => (int)$this->active,
		];
	}
}

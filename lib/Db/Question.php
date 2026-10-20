<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the `bc_question` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getOfferingId()
 * @method void setOfferingId(int $offeringId)
 * @method string getLabel()
 * @method void setLabel(string $label)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string|null getOptionsJson()
 * @method void setOptionsJson(?string $optionsJson)
 * @method int getRequired()
 * @method void setRequired(int $required)
 * @method int getSort()
 * @method void setSort(int $sort)
 * @method string getVisibleTo()
 * @method void setVisibleTo(string $visibleTo)
 * @method int getWordingRev()
 * @method void setWordingRev(int $wordingRev)
 * @method int getActive()
 * @method void setActive(int $active)
 */
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

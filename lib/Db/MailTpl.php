<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/** NH-37 seller mail-template override — subject + appended text, never logic. */
class MailTpl extends Entity
{
	protected $kind;
	protected $locale;
	protected $subject;
	protected $extraText;

	public function __construct()
	{
		$this->addType('id', 'integer');
	}
}

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the `bc_mail_tpl` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getLocale()
 * @method void setLocale(string $locale)
 * @method string|null getSubject()
 * @method void setSubject(?string $subject)
 * @method string|null getExtraText()
 * @method void setExtraText(?string $extraText)
 */
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

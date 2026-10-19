<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * bc_order.consent_text — snapshot of the exact terms wording the buyer
 * accepted at checkout (AC-3 / US-301). consent_at already stores *when*;
 * this stores *what* — wording changes afterwards must not silently
 * rewrite what was agreed.
 */
class Version1003Date20261019000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('bc_order')) {
			$t = $schema->getTable('bc_order');
			if (!$t->hasColumn('consent_text')) {
				$t->addColumn('consent_text', Types::TEXT, ['notnull' => false]);
			}
		}
		return $schema;
	}
}

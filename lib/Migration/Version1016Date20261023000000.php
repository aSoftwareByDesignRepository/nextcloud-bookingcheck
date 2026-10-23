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
 * bc_companion +locale — the device's canonical locale, captured at push
 * registration (POST /capi/v1/push). PushService fans notifications out
 * per-recipient language; NULL rows (registered before the column
 * existed, or companions that send no locale) fall back to the shop's
 * configured default_locale.
 */
class Version1016Date20261023000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('bc_companion')) {
			$t = $schema->getTable('bc_companion');
			if (!$t->hasColumn('locale')) {
				$t->addColumn('locale', Types::STRING, ['notnull' => false, 'length' => 10]);
			}
		}

		return $schema;
	}
}

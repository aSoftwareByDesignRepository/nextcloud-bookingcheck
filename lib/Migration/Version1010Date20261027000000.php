<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Drop bc_cc_sync — the spec'd per-order CRM sync ledger was superseded by
 * the bc_sync_task durable outbox (Version1002). No consumer ever wrote to
 * it; the dead table + dead entity/mapper were audit bait, not features.
 */
class Version1010Date20261027000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('bc_cc_sync')) {
			$schema->dropTable('bc_cc_sync');
		}

		return $schema;
	}
}

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
 * bc_email.next_at — queue lease + retry schedule.
 *
 * EmailQueueJob claims rows with an atomic UPDATE that sets next_at to the
 * lease expiry; a crashed worker's row becomes re-claimable when the lease
 * lapses, and markFailed pushes next_at forward for retry backoff. NULL on
 * fresh rows = due immediately.
 */
class Version1006Date20261024000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('bc_email') && !$schema->getTable('bc_email')->hasColumn('next_at')) {
			$schema->getTable('bc_email')->addColumn('next_at', Types::DATETIME, ['notnull' => false]);
		}
		return $schema;
	}
}

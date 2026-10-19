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
 * bc_sync_task — durable outbox for non-fatal sibling integrations
 * (customercheck buyer sync). Delivered by CrmSyncJob with retry/backoff;
 * never blocks the order flow (US-525).
 */
class Version1002Date20261021000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('bc_sync_task')) {
			$t = $schema->createTable('bc_sync_task');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('payload_json', Types::TEXT, ['notnull' => true]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'pending']);
			$t->addColumn('attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('next_at', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
			$t->addColumn('created_at', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
			$t->addColumn('done_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['status', 'next_at'], 'bc_synct_due');
		}
		return $schema;
	}
}

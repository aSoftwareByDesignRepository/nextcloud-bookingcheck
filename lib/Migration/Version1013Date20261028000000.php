<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * bc_abuse(upload_id, reporter) UNIQUE — the reportAbuse dedupe is a
 * read-then-insert race: two concurrent reports from the same reporter on
 * the same upload both pass the listForUpload() pre-check and insert twin
 * open reports. The index makes "one report per reporter per upload" a
 * DB-level invariant; raced losers hit the unique violation and the
 * controllers answer duplicate=true instead of 500.
 *
 * preSchemaChange collapses existing twins (keep the lowest id) so the
 * index can be created on already-dirty data.
 */
class Version1013Date20261028000000 extends SimpleMigrationStep
{
	public function preSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void
	{
		$db = \OCP\Server::get(\OCP\IDBConnection::class);
		// keep the earliest report per (upload_id, reporter); drop twins.
		// Multi-table DELETE…JOIN is MySQL-only, backtick quoting fails on
		// PostgreSQL (only *PREFIX* is rewritten, quoting passes through),
		// and MySQL refuses "DELETE … WHERE id IN (SELECT … FROM same_table)"
		// without a derived-table wrap — the double nesting below works on
		// both MariaDB and PostgreSQL.
		$db->executeStatement(
			'DELETE FROM *PREFIX*bc_abuse WHERE id NOT IN (
				SELECT keep_id FROM (
					SELECT MIN(id) AS keep_id FROM *PREFIX*bc_abuse
					GROUP BY upload_id, reporter
				) keep_ids)');
	}

	public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('bc_abuse')) {
			$t = $schema->getTable('bc_abuse');
			if (!$t->hasIndex('bc_abu_uniq')) {
				$t->addUniqueIndex(['upload_id', 'reporter'], 'bc_abu_uniq');
			}
		}
		return $schema;
	}
}

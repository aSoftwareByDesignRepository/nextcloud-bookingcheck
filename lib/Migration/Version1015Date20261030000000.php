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
 * bc_audit(prev_hash) UNIQUE — the audit chain is append-secured by a
 * "one child per node" invariant: a valid chain has exactly one row per
 * prev_hash value. Without the index, two concurrent transactions can
 * both read the same chain head and append twin children — a fork that
 * verifyChain later reports as tampering (false alarm on clean data,
 * and an alarm that cries wolf teaches auditors to ignore real breaks).
 *
 * The unique index makes the fork impossible at the storage layer: a
 * raced second writer takes a unique violation, its transaction rolls
 * back, and a retry re-reads the fresh head. Same backstop semantics as
 * tourcheck's unique (site_id, seq) chain guard.
 *
 * preSchemaChange collapses pre-existing twins (keep lowest id) so the
 * index can be created on already-forked data — a fork means the chain
 * was already unverifiable at that point; the constraint keeps the
 * damage from growing.
 */
class Version1015Date20261030000000 extends SimpleMigrationStep
{
	public function preSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void
	{
		$db = \OCP\Server::get(\OCP\IDBConnection::class);
		// keep the earliest row per prev_hash; drop fork twins. Derived-table
		// wrap is required for MySQL ("can't select from target table") and
		// is portable to PostgreSQL — mirrors Version1013.
		$db->executeStatement(
			'DELETE FROM *PREFIX*bc_audit WHERE id NOT IN (
				SELECT keep_id FROM (
					SELECT MIN(id) AS keep_id FROM *PREFIX*bc_audit
					GROUP BY prev_hash
				) keep_ids)');
	}

	public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('bc_audit')) {
			$t = $schema->getTable('bc_audit');
			if (!$t->hasIndex('bc_audit_prev_uq')) {
				$t->addUniqueIndex(['prev_hash'], 'bc_audit_prev_uq');
			}
		}
		return $schema;
	}
}

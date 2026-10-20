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
 * bc_slot(tpl_id, starts_at) UNIQUE — the materializer's read-then-insert
 * dedupe is racy: two concurrent materializeAll() runs (cron overlap or a
 * manual trigger during the sweep) can both miss the existing row and
 * insert twin slots. Twins are not cosmetic — each carries its own
 * capacity counter, so the same timeslot can be sold twice.
 *
 * The index is the DB-level invariant. Concurrent losers now get a
 * unique-violation (SlotMaterializer skips it) instead of a phantom
 * duplicate.
 *
 * preSchemaChange merges any twins that already exist: bookings must not
 * be orphaned, so every target-typed reference (order_line, waitlist,
 * reminder) and resource link is re-pointed at the kept row and the
 * sold/held counters are merged — both twins may carry real bookings.
 */
class Version1012Date20261027000000 extends SimpleMigrationStep
{
	public function preSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void
	{
		$db = \OCP\Server::get(\OCP\IDBConnection::class);
		$prefix = '*PREFIX*';
		// GROUP_CONCAT is MySQL-only — group in PHP so the step stays
		// portable across MariaDB and PostgreSQL.
		$rows = $db->executeQuery(
			'SELECT tpl_id, starts_at, id FROM ' . $prefix . 'bc_slot ORDER BY tpl_id, starts_at, id'
		)->fetchAll();
		$groups = [];
		foreach ($rows as $r) {
			$groups[(int)$r['tpl_id'] . '|' . (string)$r['starts_at']][] = (int)$r['id'];
		}
		foreach ($groups as $ids) {
			if (count($ids) < 2) {
				continue;
			}
			$keep = $ids[0]; // ORDER BY id keeps the lowest first
			foreach (array_slice($ids, 1) as $dup) {
				// resource links: re-point, but if the kept slot already
				// links the same resource the twin's link row is a pure
				// duplicate — drop it (bc_sr_uq would collide otherwise)
				$dupRes = $db->executeQuery(
					'SELECT id, resource_id FROM ' . $prefix . 'bc_slot_res WHERE slot_id = ?',
					[$dup])->fetchAll();
				foreach ($dupRes as $r) {
					$exists = $db->executeQuery(
						'SELECT id FROM ' . $prefix . 'bc_slot_res WHERE slot_id = ? AND resource_id = ?',
						[$keep, (int)$r['resource_id']])->fetchOne();
					if ($exists !== false) {
						$db->executeStatement('DELETE FROM ' . $prefix . 'bc_slot_res WHERE id = ?', [(int)$r['id']]);
					} else {
						$db->executeStatement('UPDATE ' . $prefix . 'bc_slot_res SET slot_id = ? WHERE id = ?',
							[$keep, (int)$r['id']]);
					}
				}
				// every target-typed table that can hold a slot id
				foreach (['bc_order_line', 'bc_waitlist', 'bc_reminder'] as $t) {
					$db->executeStatement(
						'UPDATE ' . $prefix . $t . ' SET target_id = ? WHERE target_type = ? AND target_id = ?',
						[$keep, 'slot', $dup]);
				}
				// both twins may have sold seats — the kept row must
				// reflect the true merged inventory (the timeslot really
				// is sold/held by both sets of bookings). Read-then-write
				// is fine here: migrations run single-threaded, and the
				// UPDATE…JOIN alternative is MySQL-only syntax.
				$counters = $db->executeQuery(
					'SELECT sold, held FROM ' . $prefix . 'bc_slot WHERE id = ?', [$dup])->fetch();
				if ($counters !== false) {
					$db->executeStatement(
						'UPDATE ' . $prefix . 'bc_slot SET sold = sold + ?, held = held + ? WHERE id = ?',
						[(int)$counters['sold'], (int)$counters['held'], $keep]);
				}
				$db->executeStatement('DELETE FROM ' . $prefix . 'bc_slot WHERE id = ?', [$dup]);
			}
		}
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('bc_slot')) {
			$t = $schema->getTable('bc_slot');
			if (!$t->hasIndex('bc_slot_tpl_uq')) {
				$t->addUniqueIndex(['tpl_id', 'starts_at'], 'bc_slot_tpl_uq');
			}
		}
		return $schema;
	}
}

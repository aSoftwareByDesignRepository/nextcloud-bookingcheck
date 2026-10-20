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
 * bc_waitlist.resource_id — the pool an allocated offer-hold belongs to.
 * Pooled timeslots keep capacity in bc_slot_res, not bc_slot: an offer
 * must remember which pool it reserved so accept/decline/expire release
 * and re-book the SAME counter (previously claims desynced pooled slots —
 * the offer held a bc_slot_res seat but the claim consumed bc_slot).
 */
class Version1008Date20261025000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('bc_waitlist') && !$schema->getTable('bc_waitlist')->hasColumn('resource_id')) {
			$schema->getTable('bc_waitlist')->addColumn('resource_id', Types::BIGINT, ['notnull' => false]);
		}
		return $schema;
	}
}

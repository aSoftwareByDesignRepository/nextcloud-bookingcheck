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
 * bc_payment.refund_claimed_at — timestamp anchoring the refund CAS
 * claim. A refund in flight carries refund_ref='pending:<rand>' plus
 * this stamp; a marker older than the claim TTL is reclaimable (the
 * worker died between claim and PSP call), a fresh one is a live
 * in-flight refund the second caller must not race.
 */
class Version1011Date20261027000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('bc_payment')) {
			$t = $schema->getTable('bc_payment');
			if (!$t->hasColumn('refund_claimed_at')) {
				$t->addColumn('refund_claimed_at', 'datetime', ['notnull' => false]);
			}
		}

		return $schema;
	}
}

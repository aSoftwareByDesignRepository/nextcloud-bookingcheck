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
 * bc_payment.refund_ref — durable record of the PSP refund reference.
 *
 * Written the moment a PSP refund call succeeds and BEFORE the order
 * state transition. A retry after a crash in between finds refund_ref
 * set and skips the PSP call — otherwise a failed transition would make
 * the admin's retry submit a second, real refund (double charge-back).
 */
class Version1004Date20261022000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('bc_payment')) {
			$t = $schema->getTable('bc_payment');
			if (!$t->hasColumn('refund_ref')) {
				$t->addColumn('refund_ref', Types::STRING, ['notnull' => false, 'length' => 190]);
			}
		}
		return $schema;
	}
}

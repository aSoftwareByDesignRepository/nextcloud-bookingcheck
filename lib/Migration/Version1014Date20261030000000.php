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
 * bc_order.vat_bp — the VAT rate RESOLVED at pricing time (offering rate,
 * or the OSS destination rate for cross-border B2C). Invoices must split
 * net/vat at the rate the order was actually priced and charged with —
 * re-deriving from the offering's current vat_bp breaks when the seller
 * edits the rate between order and issue, and is wrong for OSS orders
 * whose destination-country rate lives nowhere on the offering row.
 *
 * -1 = "recorded before the column existed" — invoice computation falls
 * back to the offering's current rate, preserving legacy behavior.
 */
class Version1014Date20261030000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('bc_order')) {
			$t = $schema->getTable('bc_order');
			if (!$t->hasColumn('vat_bp')) {
				$t->addColumn('vat_bp', 'integer', ['notnull' => true, 'default' => -1]);
			}
		}
		return $schema;
	}
}

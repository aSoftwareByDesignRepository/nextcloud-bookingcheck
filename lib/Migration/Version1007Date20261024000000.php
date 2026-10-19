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
 * Admin sweep additive columns:
 *
 *  - bc_order.staff_note      — internal ops note written from the order
 *                               detail page (the addNote endpoint previously
 *                               validated then discarded the text).
 *  - bc_promo.currency        — settlement currency for fixed-amount codes;
 *                               NULL on pre-existing rows (unenforced), new
 *                               rows always carry the seller currency.
 *  - bc_voucher.currency      — same for voucher credit.
 *  - bc_slot_tpl.resource_id  — optional resource assignment; materialized
 *                               slots get a bc_slot_res row per resource.
 */
class Version1007Date20261024000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('bc_order') && !$schema->getTable('bc_order')->hasColumn('staff_note')) {
			$schema->getTable('bc_order')->addColumn('staff_note', Types::TEXT, ['notnull' => false]);
		}
		if ($schema->hasTable('bc_promo') && !$schema->getTable('bc_promo')->hasColumn('currency')) {
			$schema->getTable('bc_promo')->addColumn('currency', Types::STRING, ['notnull' => false, 'length' => 3]);
		}
		if ($schema->hasTable('bc_voucher') && !$schema->getTable('bc_voucher')->hasColumn('currency')) {
			$schema->getTable('bc_voucher')->addColumn('currency', Types::STRING, ['notnull' => false, 'length' => 3]);
		}
		if ($schema->hasTable('bc_slot_tpl') && !$schema->getTable('bc_slot_tpl')->hasColumn('resource_id')) {
			$schema->getTable('bc_slot_tpl')->addColumn('resource_id', Types::BIGINT, ['notnull' => false]);
		}
		return $schema;
	}
}

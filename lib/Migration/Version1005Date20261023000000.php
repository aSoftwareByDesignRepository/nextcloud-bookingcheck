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
 * bc_ticket.revoked_at — ticket death record.
 *
 * Refunded/cancelled paid orders must kill their QR codes: without a
 * revocation flag a refunded buyer keeps a working door ticket.
 * checkInByToken() refuses revoked tickets; the column is set inside
 * the order transition transaction so state and tickets can't split.
 */
class Version1005Date20261023000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('bc_ticket') && !$schema->getTable('bc_ticket')->hasColumn('revoked_at')) {
			$schema->getTable('bc_ticket')->addColumn('revoked_at', Types::DATETIME, ['notnull' => false]);
		}
		return $schema;
	}
}

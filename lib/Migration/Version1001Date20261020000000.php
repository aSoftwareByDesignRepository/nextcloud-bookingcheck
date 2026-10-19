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
 * Render-time QR token storage. Check-in verifies against qr_token_hash
 * (indexable, constant-time); the encrypted copy exists solely so the
 * manage page can re-render the buyer's QR payload — a DB leak yields
 * hashes + ciphertext, never usable bearer tokens.
 */
class Version1001Date20261020000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('bc_ticket')) {
			$t = $schema->getTable('bc_ticket');
			if (!$t->hasColumn('qr_token_enc')) {
				$t->addColumn('qr_token_enc', 'string', ['notnull' => false, 'length' => 512]);
			}
		}
		return $schema;
	}
}

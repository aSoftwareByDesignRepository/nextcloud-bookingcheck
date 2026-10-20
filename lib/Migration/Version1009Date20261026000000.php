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
 * Post-v1 feature wave — one schema step for every additive feature.
 *
 *   bc_reminder    NH-20  attendee reminder dedupe (one row per sent mail)
 *   bc_abuse       NH-12  upload abuse reports → seller moderation queue
 *   bc_download    NH-10  material download analytics (append-only)
 *   bc_mail_tpl    NH-37  seller overrides for mail subject + extra text
 *   bc_pass        NH-28  annual/membership passes (BCKP: bearer tokens)
 *   bc_pass_use    NH-28  per-admission log for passes
 *
 *   bc_offering    +reminder_days, +dyn_bp/+dyn_step_pct (NH-25),
 *                  +deposit_bp (NH-18), +invoice_pay (NH-19),
 *                  +pass_scope (NH-28 linked offerings JSON)
 *   bc_price_cat   +sale_from/+sale_to (NH-23 early-bird windows)
 *   bc_event       +price_cents (NH-07 per-occurrence override)
 *   bc_order       +pay_method, +remainder_cents (NH-18/19/27)
 *   bc_companion   +push_token/+push_kind (NH-21 manager push)
 *   bc_upload      +thumb_ref (NH-15), +av_state (NH-13 fail-closed AV)
 */
class Version1009Date20261026000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('bc_reminder')) {
			$t = $schema->createTable('bc_reminder');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('order_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('target_type', Types::STRING, ['notnull' => true, 'length' => 8]);
			$t->addColumn('target_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('sent_at', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'bc_rem_pk');
			$t->addUniqueIndex(['order_id', 'target_type', 'target_id'], 'bc_rem_dedupe_uq');
		}

		if (!$schema->hasTable('bc_abuse')) {
			$t = $schema->createTable('bc_abuse');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('upload_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('reporter', Types::STRING, ['notnull' => true, 'length' => 190]);
			$t->addColumn('reason', Types::STRING, ['notnull' => true, 'length' => 500]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'open']); // open|resolved|dismissed
			$t->addColumn('created_at', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
			$t->addColumn('resolved_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
			$t->addColumn('resolved_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->setPrimaryKey(['id'], 'bc_abu_pk');
			$t->addIndex(['upload_id', 'status'], 'bc_abu_upl_idx');
			$t->addIndex(['status'], 'bc_abu_st_idx');
		}

		if (!$schema->hasTable('bc_download')) {
			$t = $schema->createTable('bc_download');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('material_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('order_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('ticket_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('actor', Types::STRING, ['notnull' => true, 'length' => 64]); // portal|manage|admin
			$t->addColumn('created_at', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'bc_dld_pk');
			$t->addIndex(['material_id'], 'bc_dld_mat_idx');
			$t->addIndex(['order_id'], 'bc_dld_ord_idx');
		}

		if (!$schema->hasTable('bc_mail_tpl')) {
			$t = $schema->createTable('bc_mail_tpl');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('locale', Types::STRING, ['notnull' => true, 'length' => 8]);
			$t->addColumn('subject', Types::STRING, ['notnull' => false, 'length' => 190]);
			$t->addColumn('extra_text', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'bc_mtp_pk');
			$t->addUniqueIndex(['kind', 'locale'], 'bc_mtp_kind_uq');
		}

		if (!$schema->hasTable('bc_pass')) {
			$t = $schema->createTable('bc_pass');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('offering_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('order_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('token_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('holder_name', Types::STRING, ['notnull' => true, 'length' => 190]);
			$t->addColumn('holder_email', Types::STRING, ['notnull' => true, 'length' => 190]);
			$t->addColumn('valid_from', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
			$t->addColumn('valid_until', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
			$t->addColumn('uses_max', Types::INTEGER, ['notnull' => false]); // NULL = unlimited
			$t->addColumn('uses', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'active']); // active|revoked|expired
			$t->addColumn('created_at', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'bc_pas_pk');
			$t->addUniqueIndex(['token_hash'], 'bc_pas_tok_uq');
			$t->addIndex(['order_id'], 'bc_pas_ord_idx');
		}

		if (!$schema->hasTable('bc_pass_use')) {
			$t = $schema->createTable('bc_pass_use');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('pass_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('target_type', Types::STRING, ['notnull' => true, 'length' => 8]);
			$t->addColumn('target_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('device', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_at', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'bc_pau_pk');
			$t->addIndex(['pass_id'], 'bc_pau_pas_idx');
			// one admission per pass per target — a pass never double-books a seat
			$t->addUniqueIndex(['pass_id', 'target_type', 'target_id'], 'bc_pau_once_uq');
		}

		if ($schema->hasTable('bc_offering')) {
			$t = $schema->getTable('bc_offering');
			if (!$t->hasColumn('reminder_days')) {
				$t->addColumn('reminder_days', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			}
			if (!$t->hasColumn('dyn_bp')) {
				$t->addColumn('dyn_bp', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			}
			if (!$t->hasColumn('dyn_step_pct')) {
				$t->addColumn('dyn_step_pct', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			}
			if (!$t->hasColumn('deposit_bp')) {
				$t->addColumn('deposit_bp', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			}
			if (!$t->hasColumn('invoice_pay')) {
				$t->addColumn('invoice_pay', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			}
			if (!$t->hasColumn('pass_scope')) {
				$t->addColumn('pass_scope', Types::TEXT, ['notnull' => false]); // JSON offering-id list; NULL = pass offering itself
			}
		}

		if ($schema->hasTable('bc_price_cat')) {
			$t = $schema->getTable('bc_price_cat');
			if (!$t->hasColumn('sale_from')) {
				$t->addColumn('sale_from', Types::DATETIME, ['notnull' => false]);
			}
			if (!$t->hasColumn('sale_to')) {
				$t->addColumn('sale_to', Types::DATETIME, ['notnull' => false]);
			}
		}

		if ($schema->hasTable('bc_event') && !$schema->getTable('bc_event')->hasColumn('price_cents')) {
			$schema->getTable('bc_event')->addColumn('price_cents', Types::BIGINT, ['notnull' => false]);
		}

		if ($schema->hasTable('bc_order')) {
			$t = $schema->getTable('bc_order');
			if (!$t->hasColumn('pay_method')) {
				$t->addColumn('pay_method', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'psp']); // psp|invoice|cash|card_link|deposit
			}
			if (!$t->hasColumn('remainder_cents')) {
				$t->addColumn('remainder_cents', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			}
		}

		if ($schema->hasTable('bc_companion')) {
			$t = $schema->getTable('bc_companion');
			if (!$t->hasColumn('push_token')) {
				$t->addColumn('push_token', Types::STRING, ['notnull' => false, 'length' => 190]);
			}
			if (!$t->hasColumn('push_kind')) {
				$t->addColumn('push_kind', Types::STRING, ['notnull' => false, 'length' => 16]); // expo
			}
		}

		if ($schema->hasTable('bc_upload')) {
			$t = $schema->getTable('bc_upload');
			if (!$t->hasColumn('thumb_ref')) {
				$t->addColumn('thumb_ref', Types::STRING, ['notnull' => false, 'length' => 190]);
			}
			if (!$t->hasColumn('av_state')) {
				$t->addColumn('av_state', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'none']); // none|clean|infected|unscanned
			}
		}

		return $schema;
	}
}

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
 * BookingCheck initial schema.
 *
 * Conventions (nextcloud-db-standards):
 * - all identifiers ≤ 27 chars; explicit short PK/index names
 * - ENUMs are `string` columns; value sets enforced in the service layer
 *   (portable across MariaDB/Postgres/Oracle)
 * - booleans are integer 0/1
 * - *_cents money columns are bigint
 * - all creates guarded by hasTable (idempotent)
 * - token/secret columns that only need verification store SHA-256 hashes;
 *   secrets that must be USED (outbound webhook signing, PSP keys) store
 *   ICrypto ciphertext (`*_enc`)
 */
class Version1000Date20261019000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// ---------------------------------------------------------------
		// bc_offering — the product being sold
		// ---------------------------------------------------------------
		if (!$schema->hasTable('bc_offering')) {
			$t = $schema->createTable('bc_offering');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('type', 'string', ['notnull' => true, 'length' => 16]); // event|course|timeslot
			$t->addColumn('slug', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('title', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('descr', 'text', ['notnull' => false]);
			$t->addColumn('status', 'string', ['notnull' => true, 'length' => 16, 'default' => 'draft']); // draft|published|archived
			$t->addColumn('price_cents', 'bigint', ['notnull' => true, 'default' => 0]);
			$t->addColumn('currency', 'string', ['notnull' => true, 'length' => 3, 'default' => 'EUR']);
			$t->addColumn('vat_bp', 'integer', ['notnull' => true, 'default' => 1900]); // basis points
			$t->addColumn('cancel_policy', 'text', ['notnull' => false]); // JSON
			$t->addColumn('book_series', 'integer', ['notnull' => true, 'default' => 0]);
			// integrations
			$t->addColumn('deck_board_id', 'bigint', ['notnull' => false]);
			$t->addColumn('talk_room', 'string', ['notnull' => false, 'length' => 190]);
			// commerce flags
			$t->addColumn('approval_required', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('approval_hold_cap', 'integer', ['notnull' => false]); // max seats under pending_approval
			$t->addColumn('waitlist_mode', 'string', ['notnull' => true, 'length' => 8, 'default' => 'allocate']); // notify|allocate
			$t->addColumn('review_display', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('logo_file_ref', 'string', ['notnull' => false, 'length' => 190]);
			$t->addColumn('accent_hex', 'string', ['notnull' => false, 'length' => 7]);
			// attendee sharing / uploads
			$t->addColumn('sharing_enabled', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('sharing_scope', 'string', ['notnull' => true, 'length' => 16, 'default' => 'occurrence']); // occurrence|offering
			$t->addColumn('upload_max_mb', 'integer', ['notnull' => true, 'default' => 50]);
			$t->addColumn('upload_quota_mb', 'integer', ['notnull' => true, 'default' => 5120]);
			// distribution
			$t->addColumn('embed_domains', 'text', ['notnull' => false]); // JSON origins; NULL = disabled
			$t->addColumn('sales_from', 'datetime', ['notnull' => false]);
			$t->addColumn('sales_to', 'datetime', ['notnull' => false]);
			$t->addColumn('tos_text', 'text', ['notnull' => false]);
			$t->addColumn('list_in_directory', 'integer', ['notnull' => true, 'default' => 0]);
			// EOL
			$t->addColumn('eol_state', 'string', ['notnull' => true, 'length' => 16, 'default' => 'none']); // none|closing|closed|warned|archived|purged
			$t->addColumn('eol_policy', 'text', ['notnull' => false]); // JSON
			$t->addColumn('eol_action_at', 'datetime', ['notnull' => false]);
			$t->addColumn('created_by', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'bc_off_pk');
			$t->addUniqueIndex(['slug'], 'bc_off_slug_uq');
			$t->addIndex(['status', 'type'], 'bc_off_stat_idx');
			$t->addIndex(['eol_state'], 'bc_off_eol_idx');
		}

		// ---------------------------------------------------------------
		// bc_event — a concrete occurrence of an event/course
		// ---------------------------------------------------------------
		if (!$schema->hasTable('bc_event')) {
			$t = $schema->createTable('bc_event');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('offering_id', 'bigint', ['notnull' => true]);
			$t->addColumn('starts_at', 'datetime', ['notnull' => true]);
			$t->addColumn('ends_at', 'datetime', ['notnull' => true]);
			$t->addColumn('capacity', 'integer', ['notnull' => true]);
			$t->addColumn('sold', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('held', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('status', 'string', ['notnull' => true, 'length' => 16, 'default' => 'scheduled']); // scheduled|cancelled|done
			$t->addColumn('series_id', 'bigint', ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'bc_evt_pk');
			$t->addIndex(['offering_id', 'starts_at'], 'bc_evt_off_idx');
			$t->addIndex(['series_id'], 'bc_evt_ser_idx');
		}

		// bc_slot_tpl — weekly template for timeslot offerings
		if (!$schema->hasTable('bc_slot_tpl')) {
			$t = $schema->createTable('bc_slot_tpl');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('offering_id', 'bigint', ['notnull' => true]);
			$t->addColumn('duration_min', 'integer', ['notnull' => true]);
			$t->addColumn('cap_per_slot', 'integer', ['notnull' => true]);
			$t->addColumn('buffer_min', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('lead_min', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('horizon_days', 'integer', ['notnull' => true, 'default' => 60]);
			$t->addColumn('weekly_rrule', 'text', ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'bc_stpl_pk');
			$t->addIndex(['offering_id'], 'bc_stpl_off_idx');
		}

		// bc_slot — materialized timeslot
		if (!$schema->hasTable('bc_slot')) {
			$t = $schema->createTable('bc_slot');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('tpl_id', 'bigint', ['notnull' => true]);
			$t->addColumn('offering_id', 'bigint', ['notnull' => true]); // denormalized for lock-free reads
			$t->addColumn('starts_at', 'datetime', ['notnull' => true]);
			$t->addColumn('ends_at', 'datetime', ['notnull' => true]);
			$t->addColumn('capacity', 'integer', ['notnull' => true]);
			$t->addColumn('sold', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('held', 'integer', ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id'], 'bc_slot_pk');
			$t->addIndex(['offering_id', 'starts_at'], 'bc_slot_off_idx');
			$t->addIndex(['tpl_id', 'starts_at'], 'bc_slot_tpl_idx');
		}

		// bc_series — a course series (all-or-nothing bookable)
		if (!$schema->hasTable('bc_series')) {
			$t = $schema->createTable('bc_series');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('offering_id', 'bigint', ['notnull' => true]);
			$t->addColumn('title', 'string', ['notnull' => false, 'length' => 190]);
			$t->addColumn('rrule', 'text', ['notnull' => false]);
			$t->addColumn('capacity', 'integer', ['notnull' => true]);
			$t->addColumn('sold', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('held', 'integer', ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id'], 'bc_ser_pk');
			$t->addIndex(['offering_id'], 'bc_ser_off_idx');
		}

		// ---------------------------------------------------------------
		// Orders
		// ---------------------------------------------------------------
		if (!$schema->hasTable('bc_order')) {
			$t = $schema->createTable('bc_order');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('ref', 'string', ['notnull' => true, 'length' => 32]); // BCK-YYYY-XXXXXX
			$t->addColumn('offering_id', 'bigint', ['notnull' => true]);
			$t->addColumn('buyer_name', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('buyer_email', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('buyer_locale', 'string', ['notnull' => true, 'length' => 10, 'default' => 'en']);
			$t->addColumn('buyer_company', 'string', ['notnull' => false, 'length' => 190]);
			$t->addColumn('billing_country', 'string', ['notnull' => false, 'length' => 2]);
			$t->addColumn('vat_id', 'string', ['notnull' => false, 'length' => 32]);
			$t->addColumn('vies_status', 'string', ['notnull' => true, 'length' => 8, 'default' => 'none']); // none|valid|invalid|down
			$t->addColumn('reverse_charge', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('amount_cents', 'bigint', ['notnull' => true]); // gross charged
			$t->addColumn('currency', 'string', ['notnull' => true, 'length' => 3]);
			$t->addColumn('status', 'string', ['notnull' => true, 'length' => 24, 'default' => 'created']);
			// created|pending_payment|pending_approval|approved|paid|failed|expired|cancelled|refunded|declined
			$t->addColumn('idem_token', 'string', ['notnull' => true, 'length' => 36]);
			$t->addColumn('manage_token_hash', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('is_test', 'integer', ['notnull' => true, 'default' => 0]); // immutable; admin-origin only
			// voucher/promo
			$t->addColumn('voucher_id', 'bigint', ['notnull' => false]);
			$t->addColumn('discount_cents', 'bigint', ['notnull' => true, 'default' => 0]);
			$t->addColumn('promo_id', 'bigint', ['notnull' => false]);
			$t->addColumn('promo_code', 'string', ['notnull' => false, 'length' => 32]);
			$t->addColumn('promo_cents', 'bigint', ['notnull' => true, 'default' => 0]);
			// approval flow
			$t->addColumn('approved_by', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('approved_at', 'datetime', ['notnull' => false]);
			$t->addColumn('consent_at', 'datetime', ['notnull' => false]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('paid_at', 'datetime', ['notnull' => false]);
			$t->addColumn('expires_at', 'datetime', ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'bc_ord_pk');
			$t->addUniqueIndex(['ref'], 'bc_ord_ref_uq');
			$t->addUniqueIndex(['idem_token'], 'bc_ord_idem_uq');
			$t->addIndex(['offering_id', 'status'], 'bc_ord_off_idx');
			$t->addIndex(['buyer_email'], 'bc_ord_mail_idx');
			$t->addIndex(['status', 'expires_at'], 'bc_ord_exp_idx');
		}

		if (!$schema->hasTable('bc_order_line')) {
			$t = $schema->createTable('bc_order_line');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('order_id', 'bigint', ['notnull' => true]);
			$t->addColumn('target_type', 'string', ['notnull' => true, 'length' => 8]); // event|series|slot
			$t->addColumn('target_id', 'bigint', ['notnull' => true]);
			$t->addColumn('line_kind', 'string', ['notnull' => true, 'length' => 8, 'default' => 'seat']); // seat|addon|voucher
			$t->addColumn('resource_id', 'bigint', ['notnull' => false]);
			$t->addColumn('price_cat_id', 'bigint', ['notnull' => false]);
			$t->addColumn('cat_name', 'string', ['notnull' => false, 'length' => 190]);
			$t->addColumn('seats', 'integer', ['notnull' => true, 'default' => 1]);
			$t->addColumn('unit_cents', 'bigint', ['notnull' => true]);
			$t->addColumn('line_cents', 'bigint', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'bc_ol_pk');
			$t->addIndex(['order_id'], 'bc_ol_ord_idx');
			$t->addIndex(['target_type', 'target_id'], 'bc_ol_tgt_idx');
		}

		// ---------------------------------------------------------------
		// Tickets (one per seat per line)
		// ---------------------------------------------------------------
		if (!$schema->hasTable('bc_ticket')) {
			$t = $schema->createTable('bc_ticket');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('line_id', 'bigint', ['notnull' => true]);
			$t->addColumn('seat_no', 'integer', ['notnull' => false]);
			$t->addColumn('attendee_name', 'string', ['notnull' => false, 'length' => 190]);
			$t->addColumn('attendee_email', 'string', ['notnull' => false, 'length' => 190]);
			$t->addColumn('resource_name', 'string', ['notnull' => false, 'length' => 190]);
			$t->addColumn('qr_token_hash', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('checked_in_at', 'datetime', ['notnull' => false]);
			$t->addColumn('checked_in_by', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('pass_apple_ref', 'string', ['notnull' => false, 'length' => 190]);
			$t->addColumn('pass_google_ref', 'string', ['notnull' => false, 'length' => 190]);
			$t->setPrimaryKey(['id'], 'bc_tkt_pk');
			$t->addUniqueIndex(['qr_token_hash'], 'bc_tkt_qr_uq');
			$t->addIndex(['line_id'], 'bc_tkt_line_idx');
			$t->addIndex(['attendee_email'], 'bc_tkt_mail_idx');
		}

		// ---------------------------------------------------------------
		// Money: payments, invoices, sequence
		// ---------------------------------------------------------------
		if (!$schema->hasTable('bc_payment')) {
			$t = $schema->createTable('bc_payment');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('order_id', 'bigint', ['notnull' => true]);
			$t->addColumn('provider', 'string', ['notnull' => true, 'length' => 16]); // mollie|stripe
			$t->addColumn('psp_ref', 'string', ['notnull' => false, 'length' => 190]);
			$t->addColumn('psp_event_id', 'string', ['notnull' => false, 'length' => 190]);
			$t->addColumn('status', 'string', ['notnull' => true, 'length' => 16, 'default' => 'open']); // open|paid|failed|refunded|chargeback
			$t->addColumn('disputed', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('amount_cents', 'bigint', ['notnull' => true]);
			$t->addColumn('raw_status', 'string', ['notnull' => false, 'length' => 190]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'bc_pay_pk');
			$t->addUniqueIndex(['provider', 'psp_event_id'], 'bc_pay_evt_uq');
			$t->addIndex(['order_id'], 'bc_pay_ord_idx');
		}

		if (!$schema->hasTable('bc_invoice')) {
			$t = $schema->createTable('bc_invoice');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('order_id', 'bigint', ['notnull' => true]);
			$t->addColumn('number', 'bigint', ['notnull' => true]);
			$t->addColumn('prefix', 'string', ['notnull' => true, 'length' => 8]);
			$t->addColumn('year', 'integer', ['notnull' => true]);
			$t->addColumn('type', 'string', ['notnull' => true, 'length' => 8, 'default' => 'invoice']); // invoice|credit
			$t->addColumn('credits_invoice_id', 'bigint', ['notnull' => false]);
			$t->addColumn('net_cents', 'bigint', ['notnull' => true]);
			$t->addColumn('vat_cents', 'bigint', ['notnull' => true]);
			$t->addColumn('gross_cents', 'bigint', ['notnull' => true]);
			$t->addColumn('pdf_hash', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('issued_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'bc_inv_pk');
			$t->addUniqueIndex(['prefix', 'year', 'number'], 'bc_inv_no_uq');
			$t->addIndex(['order_id'], 'bc_inv_ord_idx');
		}

		if (!$schema->hasTable('bc_invoice_seq')) {
			$t = $schema->createTable('bc_invoice_seq');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('prefix', 'string', ['notnull' => true, 'length' => 8]);
			$t->addColumn('year', 'integer', ['notnull' => true]);
			$t->addColumn('next', 'bigint', ['notnull' => true, 'default' => 1]);
			$t->setPrimaryKey(['id'], 'bc_isq_pk');
			$t->addUniqueIndex(['prefix', 'year'], 'bc_isq_py_uq');
		}

		// ---------------------------------------------------------------
		// Waitlist
		// ---------------------------------------------------------------
		if (!$schema->hasTable('bc_waitlist')) {
			$t = $schema->createTable('bc_waitlist');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('target_type', 'string', ['notnull' => true, 'length' => 8]); // event|slot
			$t->addColumn('target_id', 'bigint', ['notnull' => true]);
			$t->addColumn('email', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('locale', 'string', ['notnull' => true, 'length' => 10, 'default' => 'en']);
			$t->addColumn('state', 'string', ['notnull' => true, 'length' => 12, 'default' => 'waiting']); // waiting|offered|claimed|expired|declined
			$t->addColumn('offer_expires_at', 'datetime', ['notnull' => false]);
			$t->addColumn('claim_token_hash', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'bc_wl_pk');
			$t->addUniqueIndex(['email', 'target_type', 'target_id'], 'bc_wl_dedup_uq');
			$t->addIndex(['target_type', 'target_id', 'state'], 'bc_wl_tgt_idx');
			$t->addIndex(['state', 'offer_expires_at'], 'bc_wl_exp_idx');
		}

		// ---------------------------------------------------------------
		// Audit (append-only, hash-chained, PII-free diffs)
		// ---------------------------------------------------------------
		if (!$schema->hasTable('bc_audit')) {
			$t = $schema->createTable('bc_audit');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('order_id', 'bigint', ['notnull' => false]); // NULL for offering-level rows
			$t->addColumn('offering_id', 'bigint', ['notnull' => false]);
			$t->addColumn('actor', 'string', ['notnull' => true, 'length' => 64]); // uid|cron|webhook|guest|system
			$t->addColumn('action', 'string', ['notnull' => true, 'length' => 48]);
			$t->addColumn('from_state', 'string', ['notnull' => false, 'length' => 24]);
			$t->addColumn('to_state', 'string', ['notnull' => false, 'length' => 24]);
			$t->addColumn('meta', 'text', ['notnull' => false]); // JSON; PII values → "[changed]"
			$t->addColumn('prev_hash', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('row_hash', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'bc_aud_pk');
			$t->addIndex(['order_id'], 'bc_aud_ord_idx');
			$t->addIndex(['offering_id'], 'bc_aud_off_idx');
		}

		// Inbound PSP webhook dedupe
		if (!$schema->hasTable('bc_webhook_evt')) {
			$t = $schema->createTable('bc_webhook_evt');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('provider', 'string', ['notnull' => true, 'length' => 16]);
			$t->addColumn('event_id', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('received_at', 'datetime', ['notnull' => true]);
			$t->addColumn('processed_at', 'datetime', ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'bc_wevt_pk');
			$t->addUniqueIndex(['provider', 'event_id'], 'bc_wevt_uq');
		}

		// ---------------------------------------------------------------
		// Materials & portal
		// ---------------------------------------------------------------
		if (!$schema->hasTable('bc_material')) {
			$t = $schema->createTable('bc_material');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('offering_id', 'bigint', ['notnull' => true]);
			$t->addColumn('target_type', 'string', ['notnull' => false, 'length' => 8]); // event|slot; NULL = offering-wide
			$t->addColumn('target_id', 'bigint', ['notnull' => false]);
			$t->addColumn('library_id', 'bigint', ['notnull' => false]); // live link → bc_material_lib
			$t->addColumn('kind', 'string', ['notnull' => true, 'length' => 8]); // file|page
			$t->addColumn('title', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('sort', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('file_ref', 'string', ['notnull' => false, 'length' => 190]);
			$t->addColumn('page_md', 'text', ['notnull' => false]);
			$t->addColumn('avail_from', 'datetime', ['notnull' => false]);
			$t->addColumn('avail_to', 'datetime', ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'bc_mat_pk');
			$t->addIndex(['offering_id', 'sort'], 'bc_mat_off_idx');
		}

		if (!$schema->hasTable('bc_material_lib')) {
			$t = $schema->createTable('bc_material_lib');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('title', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('kind', 'string', ['notnull' => true, 'length' => 8]); // file|page
			$t->addColumn('file_ref', 'string', ['notnull' => false, 'length' => 190]);
			$t->addColumn('page_md', 'text', ['notnull' => false]);
			$t->addColumn('auto_attach', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'bc_mlib_pk');
		}

		if (!$schema->hasTable('bc_otp')) {
			$t = $schema->createTable('bc_otp');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('email', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('code_hash', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('magic_token_hash', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('attempts', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('expires_at', 'datetime', ['notnull' => true]);
			$t->addColumn('used_at', 'datetime', ['notnull' => false]);
			$t->addColumn('ip_hash', 'string', ['notnull' => false, 'length' => 64]);
			$t->setPrimaryKey(['id'], 'bc_otp_pk');
			$t->addIndex(['email', 'used_at'], 'bc_otp_mail_idx');
			$t->addIndex(['expires_at'], 'bc_otp_exp_idx');
		}

		if (!$schema->hasTable('bc_portal_sess')) {
			$t = $schema->createTable('bc_portal_sess');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('token_hash', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('email', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('expires_at', 'datetime', ['notnull' => true]);
			$t->addColumn('abs_expires_at', 'datetime', ['notnull' => true]); // 12-month absolute cap
			$t->addColumn('last_seen_at', 'datetime', ['notnull' => false]);
			$t->addColumn('ua_short', 'string', ['notnull' => false, 'length' => 190]);
			$t->addColumn('revoked_at', 'datetime', ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'bc_psess_pk');
			$t->addUniqueIndex(['token_hash'], 'bc_psess_tok_uq');
			$t->addIndex(['email'], 'bc_psess_mail_idx');
		}

		// Attendee uploads (UGC)
		if (!$schema->hasTable('bc_upload')) {
			$t = $schema->createTable('bc_upload');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('offering_id', 'bigint', ['notnull' => true]);
			$t->addColumn('occurrence_id', 'bigint', ['notnull' => false]);
			$t->addColumn('uploader_email', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('display_name', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('file_ref', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('size_bytes', 'bigint', ['notnull' => true]);
			$t->addColumn('mime', 'string', ['notnull' => true, 'length' => 100]);
			$t->addColumn('sha256', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('deleted_at', 'datetime', ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'bc_upl_pk');
			$t->addIndex(['offering_id', 'occurrence_id'], 'bc_upl_off_idx');
		}

		// ---------------------------------------------------------------
		// Resources & named seats
		// ---------------------------------------------------------------
		if (!$schema->hasTable('bc_resource')) {
			$t = $schema->createTable('bc_resource');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('offering_id', 'bigint', ['notnull' => true]);
			$t->addColumn('name', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('capacity', 'integer', ['notnull' => true]);
			$t->addColumn('sort', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('active', 'integer', ['notnull' => true, 'default' => 1]);
			$t->setPrimaryKey(['id'], 'bc_res_pk');
			$t->addUniqueIndex(['offering_id', 'name'], 'bc_res_name_uq');
		}

		if (!$schema->hasTable('bc_slot_res')) {
			$t = $schema->createTable('bc_slot_res');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('slot_id', 'bigint', ['notnull' => true]);
			$t->addColumn('resource_id', 'bigint', ['notnull' => true]);
			$t->addColumn('capacity', 'integer', ['notnull' => true]);
			$t->addColumn('sold', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('held', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('active', 'integer', ['notnull' => true, 'default' => 1]);
			$t->setPrimaryKey(['id'], 'bc_sr_pk');
			$t->addUniqueIndex(['slot_id', 'resource_id'], 'bc_sr_uq');
			$t->addIndex(['slot_id'], 'bc_sr_slot_idx');
		}

		// ---------------------------------------------------------------
		// Pricing: categories, add-ons, vouchers, promos
		// ---------------------------------------------------------------
		if (!$schema->hasTable('bc_price_cat')) {
			$t = $schema->createTable('bc_price_cat');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('offering_id', 'bigint', ['notnull' => true]);
			$t->addColumn('name', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('price_cents', 'bigint', ['notnull' => true]);
			$t->addColumn('sort', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('max_per_order', 'integer', ['notnull' => false]);
			$t->addColumn('active', 'integer', ['notnull' => true, 'default' => 1]);
			$t->setPrimaryKey(['id'], 'bc_pcat_pk');
			$t->addIndex(['offering_id'], 'bc_pcat_off_idx');
		}

		if (!$schema->hasTable('bc_addon')) {
			$t = $schema->createTable('bc_addon');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('offering_id', 'bigint', ['notnull' => true]);
			$t->addColumn('name', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('price_cents', 'bigint', ['notnull' => true]);
			$t->addColumn('max_per_order', 'integer', ['notnull' => false]);
			$t->addColumn('active', 'integer', ['notnull' => true, 'default' => 1]);
			$t->setPrimaryKey(['id'], 'bc_add_pk');
			$t->addIndex(['offering_id'], 'bc_add_off_idx');
		}

		if (!$schema->hasTable('bc_voucher')) {
			$t = $schema->createTable('bc_voucher');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('code', 'string', ['notnull' => true, 'length' => 12]);
			$t->addColumn('credit_cents', 'bigint', ['notnull' => true]);
			$t->addColumn('remaining_cents', 'bigint', ['notnull' => true]);
			$t->addColumn('valid_until', 'datetime', ['notnull' => false]);
			$t->addColumn('max_uses', 'integer', ['notnull' => true, 'default' => 1]);
			$t->addColumn('uses', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('source', 'string', ['notnull' => true, 'length' => 8, 'default' => 'manual']); // manual|order
			$t->addColumn('source_order_id', 'bigint', ['notnull' => false]);
			$t->addColumn('active', 'integer', ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'bc_vch_pk');
			$t->addUniqueIndex(['code'], 'bc_vch_code_uq');
		}

		if (!$schema->hasTable('bc_promo')) {
			$t = $schema->createTable('bc_promo');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('code', 'string', ['notnull' => true, 'length' => 32]);
			$t->addColumn('kind', 'string', ['notnull' => true, 'length' => 8]); // percent|fixed
			$t->addColumn('percent_bps', 'integer', ['notnull' => false]);
			$t->addColumn('amount_cents', 'bigint', ['notnull' => false]);
			$t->addColumn('scope', 'string', ['notnull' => true, 'length' => 12, 'default' => 'all']); // all|offerings
			$t->addColumn('valid_from', 'datetime', ['notnull' => false]);
			$t->addColumn('valid_until', 'datetime', ['notnull' => false]);
			$t->addColumn('max_uses', 'integer', ['notnull' => false]); // NULL = unlimited
			$t->addColumn('uses', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('active', 'integer', ['notnull' => true, 'default' => 1]);
			$t->setPrimaryKey(['id'], 'bc_prm_pk');
			$t->addUniqueIndex(['code'], 'bc_prm_code_uq');
		}

		if (!$schema->hasTable('bc_promo_scope')) {
			$t = $schema->createTable('bc_promo_scope');
			$t->addColumn('promo_id', 'bigint', ['notnull' => true]);
			$t->addColumn('offering_id', 'bigint', ['notnull' => true]);
			$t->setPrimaryKey(['promo_id', 'offering_id'], 'bc_psc_pk');
			$t->addIndex(['offering_id'], 'bc_psc_off_idx');
		}

		// ---------------------------------------------------------------
		// Intake questions & answers
		// ---------------------------------------------------------------
		if (!$schema->hasTable('bc_question')) {
			$t = $schema->createTable('bc_question');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('offering_id', 'bigint', ['notnull' => true]);
			$t->addColumn('label', 'string', ['notnull' => true, 'length' => 500]);
			$t->addColumn('kind', 'string', ['notnull' => true, 'length' => 12]); // text|select|checkbox|date
			$t->addColumn('options_json', 'text', ['notnull' => false]);
			$t->addColumn('required', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('sort', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('visible_to', 'string', ['notnull' => true, 'length' => 8, 'default' => 'admin']); // admin|roster|door
			$t->addColumn('wording_rev', 'integer', ['notnull' => true, 'default' => 1]);
			$t->addColumn('active', 'integer', ['notnull' => true, 'default' => 1]);
			$t->setPrimaryKey(['id'], 'bc_q_pk');
			$t->addIndex(['offering_id', 'active'], 'bc_q_off_idx');
		}

		if (!$schema->hasTable('bc_answer')) {
			$t = $schema->createTable('bc_answer');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('ticket_id', 'bigint', ['notnull' => true]);
			$t->addColumn('question_id', 'bigint', ['notnull' => true]);
			$t->addColumn('value', 'text', ['notnull' => false]);
			$t->addColumn('answered_at', 'datetime', ['notnull' => true]);
			$t->addColumn('wording_rev', 'integer', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'bc_ans_pk');
			$t->addUniqueIndex(['ticket_id', 'question_id'], 'bc_ans_uq');
			$t->addIndex(['question_id'], 'bc_ans_q_idx');
		}

		// ---------------------------------------------------------------
		// Reviews, broadcasts
		// ---------------------------------------------------------------
		if (!$schema->hasTable('bc_review')) {
			$t = $schema->createTable('bc_review');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('line_id', 'bigint', ['notnull' => true]);
			$t->addColumn('rating', 'integer', ['notnull' => true]); // 1..5
			$t->addColumn('text', 'text', ['notnull' => false]);
			$t->addColumn('status', 'string', ['notnull' => true, 'length' => 12, 'default' => 'pending']); // pending|published|hidden
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('published_at', 'datetime', ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'bc_rev_pk');
			$t->addUniqueIndex(['line_id'], 'bc_rev_line_uq');
		}

		if (!$schema->hasTable('bc_broadcast')) {
			$t = $schema->createTable('bc_broadcast');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('offering_id', 'bigint', ['notnull' => true]);
			$t->addColumn('scope', 'string', ['notnull' => true, 'length' => 24]);
			$t->addColumn('subject', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('body_md', 'text', ['notnull' => true]);
			$t->addColumn('locale_map', 'text', ['notnull' => false]);
			$t->addColumn('sent_by', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('sent_at', 'datetime', ['notnull' => true]);
			$t->addColumn('rcpt_count', 'integer', ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id'], 'bc_bc_pk');
			$t->addIndex(['offering_id'], 'bc_bc_off_idx');
		}

		// ---------------------------------------------------------------
		// Outbound webhooks
		// ---------------------------------------------------------------
		if (!$schema->hasTable('bc_webhook')) {
			$t = $schema->createTable('bc_webhook');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('url', 'string', ['notnull' => true, 'length' => 500]);
			$t->addColumn('secret_enc', 'text', ['notnull' => true]); // ICrypto ciphertext
			$t->addColumn('secret_prev_enc', 'text', ['notnull' => false]); // 24h dual-accept rotation
			$t->addColumn('secret_rotated_at', 'datetime', ['notnull' => false]);
			$t->addColumn('events_json', 'text', ['notnull' => true]);
			$t->addColumn('active', 'integer', ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_by', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'bc_wh_pk');
		}

		if (!$schema->hasTable('bc_webhook_dlvr')) {
			$t = $schema->createTable('bc_webhook_dlvr');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('webhook_id', 'bigint', ['notnull' => true]);
			$t->addColumn('event', 'string', ['notnull' => true, 'length' => 48]);
			$t->addColumn('payload_json', 'text', ['notnull' => true]);
			$t->addColumn('status', 'string', ['notnull' => true, 'length' => 12, 'default' => 'pending']); // pending|delivered|dead
			$t->addColumn('attempts', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('next_retry_at', 'datetime', ['notnull' => false]);
			$t->addColumn('delivered_at', 'datetime', ['notnull' => false]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'bc_wdl_pk');
			$t->addIndex(['status', 'next_retry_at'], 'bc_wdl_due_idx');
		}

		// customercheck sync ledger
		if (!$schema->hasTable('bc_cc_sync')) {
			$t = $schema->createTable('bc_cc_sync');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('order_id', 'bigint', ['notnull' => true]);
			$t->addColumn('remote_ref', 'string', ['notnull' => false, 'length' => 190]);
			$t->addColumn('status', 'string', ['notnull' => true, 'length' => 8, 'default' => 'pending']); // pending|done|failed
			$t->addColumn('attempts', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('synced_at', 'datetime', ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'bc_ccs_pk');
			$t->addUniqueIndex(['order_id'], 'bc_ccs_ord_uq');
		}

		// Companion-app device tokens (hashed; issued by admin for door role)
		if (!$schema->hasTable('bc_companion')) {
			$t = $schema->createTable('bc_companion');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('token_hash', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('label', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('role', 'string', ['notnull' => true, 'length' => 12, 'default' => 'door']); // door|manager
			$t->addColumn('offering_ids', 'text', ['notnull' => false]); // JSON NULL = all
			$t->addColumn('created_by', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('last_seen_at', 'datetime', ['notnull' => false]);
			$t->addColumn('revoked_at', 'datetime', ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'bc_comp_pk');
			$t->addUniqueIndex(['token_hash'], 'bc_comp_tok_uq');
		}

		// Email outbox (queue; sent by EmailQueueJob)
		if (!$schema->hasTable('bc_email')) {
			$t = $schema->createTable('bc_email');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('kind', 'string', ['notnull' => true, 'length' => 32]);
			$t->addColumn('to_email', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('locale', 'string', ['notnull' => true, 'length' => 10, 'default' => 'en']);
			$t->addColumn('payload_json', 'text', ['notnull' => true]);
			$t->addColumn('status', 'string', ['notnull' => true, 'length' => 12, 'default' => 'pending']); // pending|sent|dead
			$t->addColumn('attempts', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('sent_at', 'datetime', ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'bc_em_pk');
			$t->addIndex(['status', 'created_at'], 'bc_em_due_idx');
		}

		return $schema;
	}
}

<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud DB-Standards (auto-generated)
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Tables that must exist after bookingcheck's current migrations ran —
 * every table ever created minus those intentionally dropped by later
 * migrations. Schema-repair (Ensure*Schema) and schema-readiness probes
 * use EXPECTED_TABLES; uninstall drop lists use UninstallDropTables::TABLES
 * (which deliberately keeps dropped legacy tables for complete cleanup).
 *
 * Regenerate via:
 *     php scripts/check-nextcloud-db-standards.php sync-uninstall --app=bookingcheck
 */
namespace OCA\BookingCheck\Repair;

final class SchemaTableCatalog
{
	/** @var list<string> */
	public const EXPECTED_TABLES = [
		'bc_abuse',
		'bc_addon',
		'bc_answer',
		'bc_audit',
		'bc_broadcast',
		'bc_companion',
		'bc_download',
		'bc_email',
		'bc_event',
		'bc_invoice',
		'bc_invoice_seq',
		'bc_mail_tpl',
		'bc_material',
		'bc_material_lib',
		'bc_offering',
		'bc_order',
		'bc_order_line',
		'bc_otp',
		'bc_pass',
		'bc_pass_use',
		'bc_payment',
		'bc_portal_sess',
		'bc_price_cat',
		'bc_promo',
		'bc_promo_scope',
		'bc_question',
		'bc_reminder',
		'bc_resource',
		'bc_review',
		'bc_series',
		'bc_slot',
		'bc_slot_res',
		'bc_slot_tpl',
		'bc_sync_task',
		'bc_ticket',
		'bc_upload',
		'bc_voucher',
		'bc_waitlist',
		'bc_webhook',
		'bc_webhook_dlvr',
		'bc_webhook_evt',
	];

	/** @var list<string> tables dropped intentionally by later migrations */
	public const DROPPED_LEGACY_TABLES = [
		'bc_cc_sync',
	];
}

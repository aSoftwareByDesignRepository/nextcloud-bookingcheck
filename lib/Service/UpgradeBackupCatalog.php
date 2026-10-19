<?php

declare(strict_types=1);

/**
 * Tables and app-data paths included in pre-update upgrade backups.
 *
 * SPDX-FileCopyrightText: 2026 Nextcloud DB-Standards (auto-generated)
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Regenerate via:
 *     php scripts/sync-upgrade-backup.php --app=bookingcheck
 */
namespace OCA\BookingCheck\Service;

final class UpgradeBackupCatalog
{
	public const APP_ID = 'bookingcheck';

	public const FORMAT_VERSION = 1;

	public const APPDATA_ROOT = 'upgrade-backups';

	/** @var list<string> App-data folder names (under appdata_<instance>/bookingcheck/) to include in snapshots. */
	public const APPDATA_FOLDERS = [

	];

	public const CONFIG_MAX_SNAPSHOTS = 'upgrade_backup_max_snapshots';

	public const CONFIG_LAST_SNAPSHOT_ID = 'upgrade_backup_last_snapshot_id';

	public const DEFAULT_MAX_SNAPSHOTS = 5;

	public const MAX_SNAPSHOTS_LIMIT = 20;

	/** @var list<string> */
	public const BACKUP_TABLES = [
		'bc_addon',
		'bc_answer',
		'bc_audit',
		'bc_broadcast',
		'bc_cc_sync',
		'bc_companion',
		'bc_email',
		'bc_event',
		'bc_invoice',
		'bc_invoice_seq',
		'bc_material',
		'bc_material_lib',
		'bc_offering',
		'bc_order',
		'bc_order_line',
		'bc_otp',
		'bc_payment',
		'bc_portal_sess',
		'bc_price_cat',
		'bc_promo',
		'bc_promo_scope',
		'bc_question',
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

	/** @var list<string> */
	public const RESTORE_TABLE_ORDER = [
		'bc_addon',
		'bc_answer',
		'bc_audit',
		'bc_broadcast',
		'bc_cc_sync',
		'bc_companion',
		'bc_email',
		'bc_event',
		'bc_invoice',
		'bc_invoice_seq',
		'bc_material',
		'bc_material_lib',
		'bc_offering',
		'bc_order',
		'bc_order_line',
		'bc_otp',
		'bc_payment',
		'bc_portal_sess',
		'bc_price_cat',
		'bc_promo',
		'bc_promo_scope',
		'bc_question',
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

	public static function isBackupTable(string $table): bool
	{
		return in_array($table, self::BACKUP_TABLES, true);
	}

	public static function clampMaxSnapshots(int $requested): int
	{
		return max(1, min(self::MAX_SNAPSHOTS_LIMIT, $requested));
	}

	/**
	 * @return list<string>
	 */
	public static function existingBackupTables(callable $tableExists): array
	{
		$existing = [];
		foreach (self::BACKUP_TABLES as $table) {
			if ($tableExists($table)) {
				$existing[] = $table;
			}
		}

		return $existing;
	}

	/**
	 * @param list<string> $presentTables
	 * @return list<string>
	 */
	public static function sortedRestoreTables(array $presentTables): array
	{
		$present = array_fill_keys($presentTables, true);
		$ordered = [];
		foreach (self::RESTORE_TABLE_ORDER as $table) {
			if (isset($present[$table])) {
				$ordered[] = $table;
			}
		}

		foreach ($presentTables as $table) {
			if (!in_array($table, $ordered, true)) {
				$ordered[] = $table;
			}
		}

		return $ordered;
	}
}

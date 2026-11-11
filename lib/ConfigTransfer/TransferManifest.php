<?php

declare(strict_types=1);

/**
 * BookingCheck transfer manifest — every appconfig key and every
 * bc_* table classified exactly once
 * (gate: scripts/check-config-transfer-manifest.py).
 *
 * BudgetCheck shares the bc_* table prefix; only the tables in
 * {@see \OCA\BookingCheck\Repair\SchemaTableCatalog::EXPECTED_TABLES}
 * (plus dropped legacy bc_cc_sync) are bookingcheck's.
 *
 * The 'transfer' settings section lives in
 * {@see \OCA\BookingCheck\Support\SettingsCatalog} — BookingCheck has no
 * *SectionCatalog service file; sections are enumerated by
 * SettingsCatalog::SECTIONS and routed via page#settingsSection.
 */

namespace OCA\BookingCheck\ConfigTransfer;

final class TransferManifest extends AbstractTransferManifest
{
	private const SCHEMA_VERSION = 1;

	public function schemaVersion(): int
	{
		return self::SCHEMA_VERSION;
	}

	/** @return array<string,array> */
	public function keys(): array
	{
		return self::normaliseKeys([
			// ── access door / admin policy — app-local appconfig, written
			// per-key through SettingsController::putAccess with directory
			// existence validation (no platform restriction mirror → plain,
			// not mirrored). restricted+empty-allowlists is a legal
			// fail-closed state, so there is no cross-key invariant to
			// write atomically ──
			'access_restriction_enabled' => ['class' => 'plain', 'security' => true, 'section' => 'access'],
			'access_app_admin_user_ids' => ['class' => 'plain', 'security' => true, 'refs' => ['kind' => 'uid_list_json'], 'section' => 'access'],
			'access_allowed_user_ids' => ['class' => 'plain', 'security' => true, 'refs' => ['kind' => 'uid_list_json'], 'section' => 'access'],
			'access_allowed_group_ids' => ['class' => 'plain', 'security' => true, 'refs' => ['kind' => 'gid_list_json'], 'section' => 'access'],

			// ── payments (Settings → Payments) ──
			'psp_provider' => ['class' => 'plain', 'security' => true, 'section' => 'payments'],
			'sumup_merchant_code' => ['class' => 'plain', 'section' => 'payments'],
			'paypal_client_id' => ['class' => 'plain', 'section' => 'payments'],
			'klarna_api_uid' => ['class' => 'plain', 'section' => 'payments'],
			// Sandbox flags are environment posture — a test-mode flag must
			// never silently turn a production checkout sandboxed.
			'paypal_sandbox' => ['class' => 'env', 'section' => 'payments'],
			'klarna_sandbox' => ['class' => 'env', 'section' => 'payments'],

			// ── invoicing & tax (Settings → Invoicing & tax) ──
			'invoice_prefix' => ['class' => 'plain', 'section' => 'invoicing'],
			'seller_name' => ['class' => 'plain', 'section' => 'invoicing'],
			'seller_email' => ['class' => 'plain', 'section' => 'invoicing'],
			'seller_address' => ['class' => 'plain', 'section' => 'invoicing'],
			'seller_vat_id' => ['class' => 'plain', 'section' => 'invoicing'],
			'seller_country' => ['class' => 'plain', 'section' => 'invoicing'],
			'kleinunternehmer' => ['class' => 'plain', 'section' => 'invoicing'],
			'vies_online' => ['class' => 'plain', 'section' => 'invoicing'],
			'oss_enabled' => ['class' => 'plain', 'section' => 'invoicing'],
			'oss_vat_map' => ['class' => 'plain', 'section' => 'invoicing'],
			'datev_consultant_no' => ['class' => 'plain', 'section' => 'invoicing'],
			'datev_client_no' => ['class' => 'plain', 'section' => 'invoicing'],
			'datev_revenue_account' => ['class' => 'plain', 'section' => 'invoicing'],
			'datev_receivable_account' => ['class' => 'plain', 'section' => 'invoicing'],

			// ── booking flow (Settings → Booking flow) ──
			'order_hold_min' => ['class' => 'plain', 'section' => 'flow'],
			'approval_hold_min' => ['class' => 'plain', 'section' => 'flow'],
			'payment_ttl_min' => ['class' => 'plain', 'section' => 'flow'],
			'otp_ttl_min' => ['class' => 'plain', 'section' => 'flow'],
			'otp_max_attempts' => ['class' => 'plain', 'section' => 'flow'],
			'portal_sess_days' => ['class' => 'plain', 'section' => 'flow'],
			'waitlist_offer_h' => ['class' => 'plain', 'section' => 'flow'],

			// ── portal & sharing (Settings → Portal & sharing) ──
			'portal_enabled' => ['class' => 'plain', 'security' => true, 'section' => 'portal'],
			'directory_enabled' => ['class' => 'plain', 'security' => true, 'section' => 'portal'],
			'default_locale' => ['class' => 'plain', 'section' => 'portal'],
			// Bearer token enabling the public seller iCal feed — a
			// capability secret: redacted in the container like every
			// secret-class key; instance URLs/embed origins stay env-local.
			'ical_feed_token' => ['class' => 'secret', 'section' => 'portal'],
			'cors_origins' => ['class' => 'env', 'section' => 'portal'],

			// ── integrations (Settings → Integrations) ──
			'crm_sync_enabled' => ['class' => 'plain', 'section' => 'integrations'],
			'av_enabled' => ['class' => 'plain', 'section' => 'integrations'],
			// ICAP scanner endpoint (host:port) is per-instance infrastructure.
			'av_icap_endpoint' => ['class' => 'env', 'section' => 'integrations'],
			'wallet_google_issuer' => ['class' => 'plain', 'section' => 'integrations'],
			'wallet_google_sa_email' => ['class' => 'plain', 'section' => 'integrations'],
			'wallet_apple_pass_type_id' => ['class' => 'plain', 'section' => 'integrations'],
			'wallet_apple_team_id' => ['class' => 'plain', 'section' => 'integrations'],

			// ── license (Settings → License) — the vendor-issued key is
			// bound to this instance; prod takes its own key ──
			'license_key' => ['class' => 'env', 'section' => 'license'],

			// ── secrets — stored as ICrypto ciphertext under <name>_enc
			// (SettingsService::SECRET_KEYS). They are redacted inside the
			// encrypted container by default; with occ --include-secrets the
			// ciphertext travels and only applies where it still decrypts —
			// the hooks reject ciphertext foreign to the target instance,
			// so a cross-instance include-secrets apply can never plant
			// undecryptable PSP credentials (fail closed) ──
			'mollie_api_key_enc' => ['class' => 'secret', 'section' => 'payments'],
			'mollie_test_key_enc' => ['class' => 'secret', 'section' => 'payments'],
			'mollie_webhook_secret_enc' => ['class' => 'secret', 'section' => 'payments'],
			'stripe_secret_key_enc' => ['class' => 'secret', 'section' => 'payments'],
			'stripe_webhook_secret_enc' => ['class' => 'secret', 'section' => 'payments'],
			'sumup_api_key_enc' => ['class' => 'secret', 'section' => 'payments'],
			'paypal_client_secret_enc' => ['class' => 'secret', 'section' => 'payments'],
			'klarna_api_password_enc' => ['class' => 'secret', 'section' => 'payments'],
			'psp_test_secret_enc' => ['class' => 'secret', 'section' => 'payments'],
			'crm_sync_token_enc' => ['class' => 'secret', 'section' => 'integrations'],
			'wallet_google_secret_enc' => ['class' => 'secret', 'section' => 'integrations'],
			'wallet_apple_cert_pem_enc' => ['class' => 'secret', 'section' => 'integrations'],
			'wallet_apple_key_pem_enc' => ['class' => 'secret', 'section' => 'integrations'],

			// ── per-user values (oc_preferences — instance-personal,
			// excluded per spec AS-05; declared so the classification is
			// explicit, never silently "forgotten") ──
			'notify_paid' => 'internal',

			// ── internal bookkeeping — never transferred ──
			'uninstall_repair_pass' => 'internal',
			'upgrade_backup_last_snapshot_id' => 'internal',
			'upgrade_backup_max_snapshots' => 'internal',
			'transfer_receipts' => 'internal',
			'transfer_job_state' => 'internal',
			'transfer_last_import_at' => 'internal',
			// Dynamic/prefix key families — prefixes, not keys; members are
			// dynamic and never transferred by definition:
			//   '<secret>_enc'      covered above per member key
			//   'upgrade_backup_*'  snapshot-restore rewrites restored keys
		]);
	}

	/** @return array<string,array> */
	public function tables(): array
	{
		return self::normaliseTables([
			// ══ offering + schedule master data ══
			// created_by is provenance — carried verbatim: an offering must
			// never sink because its creator is absent on the target
			// (deskcheck convention). deck_board_id/talk_room/logo_file_ref
			// point at Deck/Talk/appdata state on THIS instance — env columns
			// land NULL on insert and stay untouched on update.
			'bc_offering' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['slug'],
				'env_columns' => ['deck_board_id', 'talk_room', 'logo_file_ref']],
			'bc_series' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['offering_id', 'title'],
				'fk' => ['offering_id' => 'bc_offering'], 'depends_on' => ['bc_offering']],
			'bc_resource' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['offering_id', 'name'],
				'fk' => ['offering_id' => 'bc_offering'], 'depends_on' => ['bc_offering']],
			'bc_price_cat' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['offering_id', 'name'],
				'fk' => ['offering_id' => 'bc_offering'], 'depends_on' => ['bc_offering']],
			'bc_addon' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['offering_id', 'name'],
				'fk' => ['offering_id' => 'bc_offering'], 'depends_on' => ['bc_offering']],
			'bc_question' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['offering_id', 'label'],
				'fk' => ['offering_id' => 'bc_offering'], 'depends_on' => ['bc_offering']],
			// One slot template per (offering, resource, duration) — resource_id
			// is nullable and matches via IS NULL in the natural key.
			'bc_slot_tpl' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['offering_id', 'resource_id', 'duration_min'],
				'fk' => ['offering_id' => 'bc_offering', 'resource_id' => 'bc_resource'],
				'depends_on' => ['bc_offering', 'bc_resource']],
			'bc_mail_tpl' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['kind', 'locale']],
			// Library rows carry page_md content; file_ref is an opaque blob
			// id inside appdata materials/ — blobs have no import path, so
			// file_ref is env (NULL on insert → re-upload on target).
			'bc_material_lib' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['title', 'kind'],
				'env_columns' => ['file_ref']],
			// Schedule instances — sold/held counters ride along; on an empty
			// target they reproduce the test fixture, on a live target merge
			// upserts them by natural key (replace wipes first).
			'bc_event' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['offering_id', 'starts_at', 'ends_at'],
				'fk' => ['offering_id' => 'bc_offering', 'series_id' => 'bc_series'],
				'depends_on' => ['bc_offering', 'bc_series']],
			'bc_promo' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['code']],
			// Composite-PK link table — no `id` column; upsert addresses the
			// row by its declared pk (engine 'pk' support).
			'bc_promo_scope' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['promo_id', 'offering_id'],
				'pk' => ['promo_id', 'offering_id'],
				'fk' => ['promo_id' => 'bc_promo', 'offering_id' => 'bc_offering'],
				'depends_on' => ['bc_promo', 'bc_offering']],
			// source_order_id is provenance into bc_order (internal) — carried
			// verbatim, never an fk (fk targets must be transferable).
			'bc_voucher' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['code']],

			// ══ derived — recomputed on the target, never transferred ══
			'bc_slot' => ['class' => 'derived'],
			'bc_slot_res' => ['class' => 'derived'],

			// ══ booking subgraph — NEVER transferred ══
			// order lines target slots/events/series through a POLYMORPHIC
			// (target_type,target_id) pair the engine cannot remap, orders
			// chain into fiscal invoices/invoice_seq and hash-chained audit,
			// and copied rows would fire reminder/webhook side effects on
			// the target. Test bookings never belong on prod — the whole
			// subgraph stays instance-local.
			'bc_order' => ['class' => 'internal'],
			'bc_order_line' => ['class' => 'internal'],
			'bc_ticket' => ['class' => 'internal'],
			'bc_payment' => ['class' => 'internal'],
			'bc_invoice' => ['class' => 'internal'],
			'bc_invoice_seq' => ['class' => 'internal'],
			'bc_answer' => ['class' => 'internal'],
			'bc_review' => ['class' => 'internal'],
			'bc_waitlist' => ['class' => 'internal'],
			'bc_pass' => ['class' => 'internal'],
			'bc_pass_use' => ['class' => 'internal'],
			// Attendee uploads point at appdata blobs (sha256'd files);
			// moderation state on uploads that do not transfer is dead weight.
			'bc_upload' => ['class' => 'internal'],
			'bc_abuse' => ['class' => 'internal'],
			// Attached materials: polymorphic target + appdata file_ref.
			'bc_material' => ['class' => 'internal'],

			// ══ instance-bound / queue / token state — NEVER transferred ══
			'bc_companion' => ['class' => 'internal'],  // door/manager device bearer tokens
			'bc_portal_sess' => ['class' => 'internal'], // customer portal sessions
			'bc_otp' => ['class' => 'internal'],         // ephemeral OTP codes
			'bc_sync_task' => ['class' => 'internal'],   // CRM sync queue
			'bc_email' => ['class' => 'internal'],       // mail outbox queue
			'bc_reminder' => ['class' => 'internal'],    // sent-reminder dedupe
			'bc_webhook' => ['class' => 'internal'],     // endpoint URL + encrypted secrets
			'bc_webhook_evt' => ['class' => 'internal'], // PSP event dedupe
			'bc_webhook_dlvr' => ['class' => 'internal'],// delivery retry queue
			// Dropped legacy table (created in V1000, dropped in V1010) —
			// declared so the gate sees every bc_* table classified once.
			'bc_cc_sync' => ['class' => 'internal'],

			// ══ logs — NEVER transferred ══
			'bc_audit' => ['class' => 'log'],     // hash-chained audit trail
			'bc_download' => ['class' => 'log'],  // material download journal
			'bc_broadcast' => ['class' => 'log'], // sent-broadcast history
		]);
	}
}

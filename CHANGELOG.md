# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.10.5 - 2026-11-24

### Fixed

- restore color-scheme pins in synced check-shell tokens
- repoint dead --bck-font-mono ref at fleet --bck-mono token
- collapse vsprintf %% escapes in JS t()/tf(); heal license de cell
- picker state rows lose dead-click option affordance
- l10n lazy-plural heal + pack resyncs + companion INVALID_URL copy
- sweep silent-empty + uid-only directory search family-wide; add gate
- licenseStatus view-data contract + pl license cells
- license-page pack re-sync, overlay armor, settings POST postfix, l10n catalogues
- bookingcheck — re-render transfer.css vs donor head d8c5a2e593 (dialog footer pin)
- replace-confirm token real width — donor tpl + re-render
- bookingcheck — sync config-transfer pack (positional t() interpolation)
- enable platform local-address guard + forbid redirects
- widgetSnapshot route inside routes array + unique-index conflict-path adjudication
- pt role -> função (glossary alignment)
- real-lexicon gate + azc crit9 closure + 769px boundary migration

### Added

- enroll app into atlas/clio farms + M1.6/M3 work docs
- add Russian (ru) catalog
- described-option dash copy is a fail-tier choice-card defect; transfer pack re-synced everywhere
- unify Check-family licensing on shared license-page pack
- bookingcheck pack l10n + form rhythm — post-seal delta reverify
- bookingcheck config & data transfer — encrypted test→prod pack
- widget-snapshot endpoint
- front-controller URL gates for web + mobile surfaces
- add European Portuguese (pt) catalog — 712 keys

### Changed

- drop deprecated no-op setAccessible on reflection
- setAccessible on dropAllTablesAndMetadata reflection
- complete App Store package — files, screenshot layout, schema-valid info.xml
- define --bck-mono + muted-on-tint tokens (check-shell sync)
- l10n: zh terminal-mark parity on 'e.g. Anna M.' example value
- l10n: heal bookingcheck catalog quality + normalize key order (reopen lane)
- l10n: formal license-page pack + informal overlay, TW pipeline terms, allowlist
- workspace sync: farm/atlas state, l10n pack heals, docs bug entries, mail asset rebuild, submodule pointer bumps
- shared-pack sources, transfer-sheet fix, farm reopen evidence
- chore!: rename portalcheck → kanzleicheck family-wide
- farm closeout sweep — submodule bumps, icon-family contract, backup-guard hardening
- transfer.css dialog-margin pack sync, farm deltas, contacts vendor rebuild
- sync — farm state, doc decisions, ticketcheck planning, mail build, submodule pins
- bookingcheck craft spec — geometry + interpolation proofs
- l10n: zh-Hant/zh_TW rollout + tr/zh handbook plumbing
- l10n: add zh_CN catalog
- l10n: complete translations across the app language set
- workspace checkpoint — farm artifacts, docs, submodule pointers
- nav-called report exports 412 + scope-strip select chevron — r10/r11 intake fix
- fix r10/r11 reject — device-card Pro dead-end + artifact hygiene
- disambiguate upload-abuse button — 'Report' → 'Report this file'
- restrict 'api' device role to documented read-only contract
- dual re-seal audiocheck + budgetcheck — submodule pins, verdicts, ledgers
- device-token pairing UI on Settings → Integrations
- workspace sync — farm artifacts, submodule bumps, carecheck shot06 bug entry
- fixer r8 — wire LOCK_SHARED on all 11 bg jobs + table-id docblock + pair-screen statusbar
- scope manager-stats assertion to fixture offerings in door-kinds test
- occ bookingcheck:seed-demo — idempotent demo dataset for companion + Play review
- atlas-farm batch sync — submodule bumps, farm artifacts, docs, planning 2026-10-25
- e2e craft leg — wait for server-side field errors before shooting
- form-rhythm fix + bck-checkbox rename + l10n bare-t() catalog tail
- coordinator wake — arbeitszeitcheck re-sealed exit_met @3.5.17 (dual delta ACCEPT @a71145d); ek web_api reaped+respawned; sbdlicenseops lanes spawned
- SEALED @3.5.17 — dual DELTA ACCEPT @104abb3/8efd7c0 (functional r15 + visual, rg FULL f=0 w=0); l10n_doubled_token intake verified closed; emulator-5600 released (AVD kept). cuc promoted in_flight — fixer on l10n_doubled_token intake (33 findings, spec written); bc ds_chrome spawned post-web_api-reverify.
- button variants + JS tables + H1 — visual r5 must_fix cluster
- catalog residual wire strings, per-recipient push locale, wire coverage test
- localize API error envelopes at the serialization boundary
- page-route 404s render HTML error pages, not JSON blobs
- offering lifecycle UI + translated status badges; fix duplicate() 500
- intake must_fix — overview stat strip key drift + shadow render path
- r1 must_fix scope — sysnav safe-area, pub-checkout scroller, undo copy + farm ratchet
- full-rerun lane closeout — sysnav contrast, DS/security/l10n fixes

## 0.10.4 - 2026-11-21

First documented release — earlier development predates tracked changelog
entries. BookingCheck is a complete bookable-capacity commerce app for
Nextcloud: sell seats, courses, timeslots and tickets with guest checkout,
real payments and gapless invoicing.

### Added
- Offerings: one-off events, recurring course series and bookable timeslots
  with atomic seat reservation and capacity that never oversells.
- Guest checkout without a Nextcloud account; email-OTP participant portal
  for self-service order management.
- Payments via Mollie and Stripe with webhook-confirmed state, refunds,
  vouchers and promo codes.
- Gapless invoicing with credit notes, e-invoice output and DATEV-compatible
  export.
- QR tickets and wallet passes; check-in scan, roster and walk-up sales via
  the licensed BCK2 companions (Manager & Scan).
- Waitlist with automatic seat offers, reviews, embed widget for external
  sites, and ICS calendar feeds.
- Outbound webhooks, CRM sync, broadcast mails, and encrypted config/data
  transfer between test and production instances.
- Hash-chained append-only audit journal with a verification background job.
- Access door (open/restricted + groups/users + app-admin role), admin
  settings pages, device-token pairing for companion apps.
- UI in 15 languages; `occ bookingcheck:seed-demo` for an idempotent demo
  dataset.

### Fixed
- Localization: Russian catalog added; catalog quality heals across locales
  (plural forms, vsprintf escapes, terminal-mark parity); API error envelopes
  localized at the serialization boundary.
- CSS: `--bck-mono` and muted-on-tint tokens defined; dead font token
  repointed (check-shell sync).
- Pickers: silent-empty and uid-only directory-search states fixed
  family-wide; dead-click option affordances restored.
- Front-controller URL gates for web and mobile surfaces; page-route 404s
  render HTML error pages instead of JSON blobs.
- Webhooks: platform local-address guard enabled, redirects forbidden;
  `api` device role restricted to the documented read-only contract.
- Config-transfer: positional `t()` interpolation, dialog footer pin and
  replace-confirm width fixes re-rendered from donor head.
- Background jobs: `LOCK_SHARED` wired on all 11 jobs; unique-index
  conflict-path adjudication; form-rhythm and checkbox naming heal.

# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

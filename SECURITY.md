# Security Policy

## Supported versions

Security fixes are applied to the latest published BookingCheck release on the
Nextcloud App Store and to the `main` / default branch of
[nextcloud-bookingcheck](https://github.com/aSoftwareByDesignRepository/nextcloud-bookingcheck).

## Reporting a vulnerability

Do **not** open a public GitHub issue for security problems that could expose
data, credentials, payment details, or a practical exploit path.

Email the maintainer listed in `appinfo/info.xml`
(`info@software-by-design.de`) with:

- App version (`occ app:list` / `appinfo/info.xml`)
- Nextcloud and PHP versions
- Steps to reproduce
- Impact assessment (who can trigger it, what is exposed)

We aim to acknowledge reports within a few business days.

## Please do not include

Production passwords, session cookies, payment provider credentials or
webhook secrets, buyer personal data, order exports, or live hostnames in
public trackers or screenshots attached to public issues.

## Notes for operators

- Buyer data (names, emails, payment references) is personal data — restrict
  staff access via the built-in access door (groups, users, app-admin role).
- Webhook endpoints authenticate against the provider secret you configure;
  keep Mollie/Stripe credentials in the admin settings, never in tickets.
- The audit journal is append-only and hash-chained; verify it any time via
  the built-in chain verification job.
- Backups are taken before upgrades via the `BackupBeforeUpdate` repair
  step (see `occ bookingcheck:upgrade-backup`).

# Changelog

What changed in each release, written for the person deciding whether to
update. Full notes with install and update instructions are on the
[releases page](https://github.com/gumslone/doba/releases).

Doba is pre-1.0: no hotel has run on it in production yet, and every
release so far is marked pre-release for that reason.

## Unreleased

- Project housekeeping: contributing guide, security policy, issue
  templates and this changelog.

## v0.3.0 — 2026-09-09

- **Apartments.** A room type is a room or an apartment, sold through the
  same funnel: a cleaning fee charged once per stay, a minimum stay of its
  own, bedrooms and bathrooms, `schema.org/Apartment`.
- **The admin account.** TOTP second factor with recovery codes,
  remember-me, and `doba:admin:reset-password` from the shell.
- **The desk.** Phone and walk-in bookings, moving a stay without breaking
  the calendar, hotel settings and room types editable in the panel,
  credit notes for cancelled invoiced stays.
- **Offsite backups and alerts.** Snapshots copied to any Laravel disk; a
  mail when a job fails, a backup fails or availability drifts.
- Two rooms on one booking, verified reviews, a returning-guest discount,
  and four feature flags that had never been read.
- **Hardening.** Sanitised HTML on every editor field, no inline script
  under the CSP, erasure takes reviews with it.
- Enquiries inbox with answers sent as the hotel's own mail, editable
  wording for the guest mails, a phone-sized housekeeping list, and an
  invoice CSV with VAT split by rate.
- **Fixed:** eight places counted "today" on the server's clock instead of
  the hotel's; a primed search dropped its departure row for a
  hotel-timezone check-out; a timestamp was printed above the checkout
  title.

Two migrations: apartment fields on room types and bookings, reply columns
on enquiries.

## v0.2.0 — 2026-08-30

- **Partner API hardened.** Hand-written OpenAPI 3.1 contract enforced by
  tests. **Fixed:** one idempotency key could take two rooms when a retry
  overtook its original; the key is now claimed before the work.
- **Updates that cannot leave a broken hotel.** Health checks before and
  after, from a fresh process, before the site reopens.
- **Two installers.** `doba-installer.php` uploaded by FTP, and a curl-able
  `install.sh`, both smoke-tested against the tarball each release ships.
- Balance payment from the guest's manage page, city tax computed and
  invoiced on its own line, pre-arrival and post-stay mail.
- **The guest book.** Profiles, stay history, GDPR export and erasure, a
  retention clock.
- **Physical rooms.** Numbers, front-desk assignment with a
  no-double-booking guarantee, housekeeping status.
- **Directory listing (opt-in).** `/.well-known/doba.json` and a
  credential-free live quote.
- Ukrainian and Polish across the guest surface, Cyrillic URLs included.

## v0.1.0 — 2026-08-18

First public release: availability and rate engine with holds, row-level
locking and a nightly reconciler; the guest booking funnel; payments
(Stripe, PayPal, LiqPay, crypto, manual); invoices with a per-VAT-rate
breakdown; two-way iCal channel sync; restaurant and bar with a menu; promo
codes; extras; front desk; reports; the install wizard; backups; a safe
in-place updater; and partner API v1.

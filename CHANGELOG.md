# Changelog

What changed in each release, written for the person deciding whether to
update. Full notes with install and update instructions are on the
[releases page](https://github.com/gumslone/doba/releases).

Doba is pre-1.0: no hotel has run on it in production yet, and every
release so far is marked pre-release for that reason.

## v0.4.0 — 2026-09-22

- **Fixed before release:** the new `.htaccess` used a `LocationMatch`
  section, which Apache does not allow there and answers with a 500 to
  every request. Caught by the image smoke test; a test now forbids such
  directives in `.htaccess`.

- Project housekeeping: contributing guide, security policy, issue
  templates and this changelog.
- **The scheduler without cron.** Where a host offers no cron, visitor
  traffic runs the scheduler after the response has gone out; a real cron
  always wins, and the health page says which one is doing the work.
- **Fixed:** production forced every URL to https even when the site
  address was http, so a first look on localhost or a LAN redirected to a
  port that speaks no TLS. It now follows the declared site address, and
  the health page warns about plain http on a public address.
- **Accessibility and page speed are now tested in CI.** Every push boots
  the demo hotel and runs a WCAG 2.1 AA audit and Lighthouse over the pages
  a guest lands on. The first run found that the brand gold and the faint
  grey failed 4.5:1 on 47 elements per page; the theme now derives
  text-safe colours from whatever preset or brand colour a hotel picks, so
  an eyebrow in the house gold is darkened exactly as far as it needs.
- **The admin installs as a phone app** (a web manifest and icons): the
  front desk and the housekeeping list from the home screen.
- **The thank-you mail says why to come back direct**: a guest who now
  qualifies for the returning-guest discount is told the percentage and
  given the link.
- **The admin panel in six languages.** Every member of staff picks their
  own language under *Your account*; the login screen offers them too. The
  install wizard is translated as well. A test pins key parity and
  placeholder survival across all of them.
- **Google rates**: an admin page (and CSV) listing, per night for the next
  90 days, the lowest final price a guest could book on the website — the
  column to copy into the Google Business Profile so the hotel's own site
  gets a free booking link with a price next to the portals. The research
  behind it is in `docs/google-free-booking-links.md`.
- **Online check-in** (`FEATURE_ONLINE_CHECKIN=true`): from three days before
  arrival the guest fills in the registration form for the whole party on
  their booking page, in six languages; the desk prints it for the
  signature. Stored encrypted, included in GDPR exports, destroyed on
  erasure and a year after departure. Arrival instructions (a key-box
  code) appear only to a checked-in guest, around arrival.
- **Gift vouchers** (`FEATURE_VOUCHERS=true`): ordered on the website in six
  languages, paid to the hotel directly, activated in the admin and mailed
  to the buyer as a PDF; or sold at the desk. Redeemed by code on the
  guest's booking page as a *payment* — never a discount — with the rest
  kept for next time, and refunded back onto the voucher.
- **Unfinished-booking reminder** (`DOBA_MAIL_RECOVERY=true`, off by
  default): one mail about an hour after a guest's unpaid hold expires,
  only while the room is still free, with a link back to the same room and
  dates. Off by default because in several countries it needs consent.
- **The installer explains PHP versions.** When the host runs an older PHP,
  `doba-installer.php` guesses the control panel (cPanel, Plesk,
  DirectAdmin, IONOS) and shows where the setting lives, step by step, with
  a message to send the host if 8.4 is not offered.
- **A landing page for hoteliers** at gumslone.github.io/doba, in English
  and German, with a commission calculator.
- **Fixed:** the guest theme's heading styles leaked into the admin and
  overrode every size utility there.
- **Public demo mode** (`DOBA_DEMO=true`): builds a living demo hotel on
  first boot, prints the admin login on every page, keeps the desk working
  while making content, mail, settings and the updater read-only, never
  mails or charges, and rebuilds itself nightly. See
  `docs/demo-hosting.md`.
- **Fixed:** the directory's ETag could be cut a second before the install
  id was stored, so a hub's first conditional GET got a 200.
- **Docker image** (`ghcr.io/gumslone/doba`, amd64 and arm64): one
  container, one `/data` volume, the same wizard; updating is pulling a
  newer image. The env writer now writes through a symlinked `.env`, which
  also fixes release-per-folder deploys.

Five migrations: gift vouchers, booking registrations, a reply on enquiries,
a recovery stamp on bookings, and a language on users. Every new feature is
off until switched on.

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

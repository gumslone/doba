# Security policy

Doba stores guests' names, addresses and booking history, and it takes
payments. A vulnerability here is somebody's personal data, so reports are
taken seriously and answered quickly.

## Supported versions

Doba is pre-1.0. Security fixes land on `main` and ship in the next
release; only the **latest release** is supported. Updating is one click
under *Admin → Update*, and the updater refuses to leave a broken site
behind.

## Reporting a vulnerability

**Please do not open a public issue.**

Use GitHub's private reporting instead: on the repository, open
**Security → Report a vulnerability**. That reaches the maintainer
privately and gives us a place to work on the fix together.

Please include:

- what an attacker can do, and what they need (an account? a booking link?)
- the steps or a proof of concept
- the version (shown at the bottom of *Admin → Update*) and the database
  engine

You will get an acknowledgement within **3 working days** and an
assessment within **10**. If the report is valid we will agree a disclosure
date with you, fix it, release, and credit you in the release notes unless
you prefer otherwise.

## In scope

- the booking funnel, guest manage links and invoice downloads
- the admin panel, login, two-factor and password reset
- the partner API, idempotency and webhooks (signature, replay)
- the installers (`install.sh`, `doba-installer.php`) and the updater
- stored or reflected XSS, CSRF, SQL injection, SSRF, path traversal,
  privilege escalation, and any way to read another guest's data

## Out of scope

- findings that require an already-compromised server or admin account
- missing hardening on a demo or development install (`APP_DEBUG=true`)
- volumetric denial of service
- reports from automated scanners with no demonstrated impact

## What Doba already does

So you know where not to spend your time: a strict Content-Security-Policy
with no inline script, HTML Purifier on every editor field, encrypted TOTP
secrets and hashed recovery codes, HMAC-signed webhooks with the timestamp
inside the signature, idempotency keys claimed before work, invoice and
guest downloads behind unguessable tokens or the admin session, and a
health check that refuses to run production with debug mode on.

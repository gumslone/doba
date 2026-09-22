# Doba for web agencies

One agency that runs ten small hotels brings more of them onto Doba than
any amount of marketing. This page is the short version of what an agency
needs to know: what you may do with it, how to install it for a client, how
to make it theirs, and how to keep it updated without a support contract.

## What you may do

Doba is MIT-licensed. You may install it for clients, charge for doing so,
re-theme it, change the code, and keep your changes private. You do not owe
anybody a licence fee, attribution on the site, or a share of the bookings.
The only obligation is the licence notice in the source, which the tarball
carries.

Nothing phones home. There is no account with us, no usage report and no
kill switch; a hotel you set up in 2026 runs in 2036 whether or not this
repository still exists.

## Installing for a client

The same three ways as for anybody, chosen by the hosting you have:

- **Shared hosting with FTP** — upload `doba-installer.php` from the
  [latest release](https://github.com/gumslone/doba/releases), open it in a
  browser. It needs PHP 8.4; if the host runs an older one, the installer
  shows where the setting lives for cPanel, Plesk, DirectAdmin and IONOS.
- **A VPS or your own Docker host** —
  `docker run -d -p 8080:80 -v hotel-data:/data -e DOBA_URL=https://hotel.example ghcr.io/gumslone/doba`
  behind Caddy or Traefik. One volume holds everything the hotel owns.
- **A shell** — `curl -fsSL https://raw.githubusercontent.com/gumslone/doba/main/scripts/install.sh | bash`.

Then the wizard: language, a blocking server check, the database, the
hotel, the owner account, the rooms. Ten minutes. Hand the owner account to
the client and keep none of the credentials — the owner can create staff
accounts and reset passwords from the shell with `doba:admin:reset-password`.

Do the client's DNS, SPF and DKIM: *Admin → Mail* prints the exact records.
Confirmations that land in spam are the most common "it does not work".

## Making it theirs

Three layers, from cheapest to deepest. Prefer the shallow one that does the job.

1. **Settings.** Name, address, photos, texts in every language, a colour
   preset or the client's own two brand colours, fonts, and custom CSS —
   all under *Admin → Styles* and *Hotel settings*. Most re-brands end
   here. The theme derives readable text colours from whatever you pick, so
   a pale brand gold will not produce grey-on-white.
2. **A theme.** `resources/views/themes/default` is the guest site. Copy it
   to `resources/views/themes/<client>` and set `DOBA_THEME=<client>`; the
   theme loader falls back to the default for any view you do not
   override, so a theme can be one file. Blade, Tailwind, no build step
   beyond `npm run build`. Keep the `@vite` include, the `<head>` partial
   (that is where hreflang, canonical, JSON-LD and the CSP nonce live) and
   the data attributes the behaviour script hangs off.
3. **The code.** It is a Laravel 12 application with a documented
   architecture (`docs/architecture.md`) and 650 tests. If you change how
   money, dates or inventory work, run the suite on both SQLite and MySQL.

Custom pages, events, the restaurant menu, FAQs and the legal pages are
content, edited in the admin, not code.

## Updating

*Admin → Update* checks the server, snapshots the database, migrates, and
refuses to reopen the site unless it actually serves; the snapshot is
restored if anything fails. With a shell, `php artisan doba:update`. With
Docker, pull the newer image. A theme you added survives an update; the
default theme's files are replaced, which is why customisation goes in a
theme of its own.

Releases are pre-releases until a hotel has run on one in production.
`CHANGELOG.md` says what changed in words a client can read.

## Backups

Nightly by default, database and photos as one set, kept for ten sets,
optionally copied off the machine to any S3-compatible bucket. A failed
backup mails the hotel. Test the restore once, under *Admin → Update →
Backups*, before you go live.

## Where to ask

[GitHub Discussions](https://github.com/gumslone/doba/discussions) for
questions, issues for bugs, [SECURITY.md](../SECURITY.md) for anything a
guest's data could hang on. An agency that runs several installs is exactly
who this project wants to hear from.

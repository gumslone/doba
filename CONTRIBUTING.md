# Contributing to Doba

Thank you for looking. Doba is a booking engine: a bug here is a hotel
selling a room twice or charging a guest the wrong amount, so the bar for
a change is "proven", not "looks right". That is less work than it sounds,
because the tooling does most of the proving.

## Ways to help that are not code

- **Run it for a real property** and tell us what was confusing. Nothing is
  more valuable right now.
- **Translations.** Guest-facing texts live in `lang/<locale>/*.php`. A test
  pins key parity across all shipped languages, so a missing key fails CI
  instead of reaching a guest.
- **Hosting notes.** If you installed Doba on a shared host, tell us which
  one and what you had to click. That becomes documentation for the next
  person.

## Setting up

The fastest way is the dev container: open the repository in GitHub
Codespaces (or VS Code with Dev Containers) and everything is installed,
migrated and seeded for you.

By hand you need PHP 8.4+, Composer 2 and Node 20:

```bash
composer install
npm ci && npm run build
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan serve
```

## Before you open a pull request

```bash
vendor/bin/pint
vendor/bin/phpstan analyse --memory-limit=1G
php artisan test
```

CI runs the suite on **SQLite and MySQL**, on PHP 8.4 and 8.5. If you can,
run MySQL locally too — the two engines disagree about JSON key order, date
arithmetic and locking, and those disagreements are where real bugs hide:

```bash
DB_CONNECTION=mysql DB_DATABASE=doba_test DB_USERNAME=root php artisan test
```

## House rules

These are the conventions a reviewer will check. Each one exists because
the alternative already caused a bug once.

- **Money is integer minor units**, never floats or `DECIMAL`. Percentages
  are basis points.
- **Business dates use the hotel's timezone**:
  `CarbonImmutable::today(config('doba.timezone'))`, never a bare `today()`.
  The server runs in UTC and a Berlin front desk is two hours ahead of it.
- **Every change to inventory happens under the availability row locks**, in
  one transaction. Read `app/Domain/Booking/BookingService.php` before
  touching it.
- **Editor-supplied HTML is sanitised on write and on render** with
  `App\Support\Html::clean()`. Everything else is escaped.
- **No inline script or inline handlers.** The Content-Security-Policy is
  `script-src 'self'`; behaviour hangs off `data-*` attributes in
  `resources/js/behaviours.js`.
- **The partner API is a contract.** Changing a response means changing
  `resources/api/openapi.yaml`, running `php artisan doba:openapi`, and the
  contract tests will tell you what else you broke.
- **A guest-facing string goes into all six languages.** A staff-facing one
  goes into `lang/en/admin.php`.
- **A feature comes with a test.** Routing, `hreflang`, canonicals, the
  sitemap and structured data especially — they fail silently.

## Commits and pull requests

One logical change per pull request. Write the commit message for the
person reading `git log` in two years: what changed and, above all, why.
If the change fixes a bug, say how the bug showed itself.

## Reporting a security problem

Please do not open a public issue. See [SECURITY.md](SECURITY.md).

## Licence

By contributing you agree that your contribution is released under the
[MIT Licence](LICENSE) that covers the project.

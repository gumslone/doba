# Doba — Architecture & Site Plan

**Product:** **Doba** — booking, calendar and management platform for independent hotels. First market: Western Europe (DE / FR / NL / EN), with PL and UA to follow — where the name becomes literal: *doba* is the everyday word for one hotel night.

**Stack:** Laravel 12 (PHP 8.3) · Apache 2.4 · MySQL 8 *or* SQLite (configurable) · Blade + Alpine.js + Tailwind
**Model:** one shared codebase, **one install per hotel** — each hotel gets its own domain, its own `.env`, its own database and its own uploads directory.
**Scope:** public booking site with calendar, online payments, rate & availability rules, OTA/channel sync, multilingual guest emails, editable CMS pages, a full admin panel, a **first-run installation wizard** (§16) and a **documented public REST API with webhooks** (§17) that channel managers, agencies and partner sites connect to.

---

## 0. Naming conventions

The product is **Doba**. Everything technical uses `doba`, so the word "hotel" always means *a hotel*, never *the software* — keeping those apart from day one avoids the rename you'd otherwise face the first time `hotel_id` and `HOTEL_THEME` mean two different things in the same file.

| Thing | Value |
|---|---|
| Repository / composer package | `doba/platform` |
| Config file | `config/doba.php` |
| Env prefix | `DOBA_*` |
| Artisan namespace | `doba:install`, `doba:new`, `doba:migrate-to-mysql` |
| Install root on the host | `/var/www/doba/<hotel-slug>/` |
| php-fpm pool / system user | `doba-<hotel-slug>` |
| API user agent & webhook signature header | `Doba/1.0`, `X-Doba-Signature` |
| Product domain | see below |

### Domains — checked August 2026 against registry RDAP

| Domain | Status |
|---|---|
| `doba.com` | **taken** — registered 2002 via Xin Net, active until 2028. Not obtainable at a sane price. |
| `doba.de` | **taken** — active since before 2015, real nameservers (EinsDNS), so an operating site rather than a parker. |
| `getdoba.com` | **available** |
| `trydoba.com` | **available** |
| `usedoba.com` | **available** |
| `dobahotel.com` | **available** |
| `doba.app`, `doba.eu` | not verifiable via public RDAP — check at a registrar |

**Recommendation: `getdoba.com` as the primary**, with `trydoba.com` and `usedoba.com` registered defensively and redirected. The `get-` prefix is standard for B2B SaaS and reads as deliberate rather than second-choice; `dobahotel.com` is the fallback if you'd rather the domain say what the product does.

**The exact-match domain being taken is not a blocker here, but it is a signal to act on:** a bare four-letter `.com` on an unrelated US business means no trademark conflict in your class or your market, but it also means you will never rank first for the bare word "doba". Since none of your customers will ever search for "doba" cold — they search for "hotel booking software" and arrive through your marketing — this costs you very little. What it *does* mean is that the EUIPO filing (classes 42 and 43) matters more than usual: it's the thing that makes the name yours in Europe regardless of who holds the `.com`. Do that before the first customer signs, not after.

Also check `doba.de` before committing to a DACH-first launch — it resolves to a real site, and if that turns out to be a German business in a related field, the calculus changes. Everything else here is unaffected.

---

## 1. Decisions made up front

| Question | Decision | Why |
|---|---|---|
| Tenancy | Separate install per hotel | Maximum isolation, per-hotel customisation and per-hotel backup/restore. No `hotel_id` in any query, no risk of leaking one hotel's data into another's. |
| Database | Portable MySQL **or** SQLite, chosen per install via `DB_CONNECTION` | Small pensions run on SQLite with zero setup; larger hotels use MySQL. Migrations and queries are written against the portable subset. |
| Web server | Apache with one `<VirtualHost>` per hotel | Matches the per-install model; `mod_rewrite` + Let's Encrypt per domain. |
| Front end | Server-rendered Blade + Alpine.js | Cheap to host, SEO-friendly (important for hotel traffic), no separate SPA build/deploy per hotel. |
| Admin panel | **Filament v4** | Gives CRUD, auth, forms, tables and filters for a fraction of the effort of hand-building. Note what it does *not* give: roles come from `spatie/laravel-permission` (+ Filament Shield), media from a plugin, and there is **no calendar** — the availability grid in §6 is a bespoke Livewire component. Budget it as such. |

### The trade-off you accepted, and how the plan absorbs it

"Separate install per hotel" is the strongest isolation but the heaviest maintenance: 20 hotels means 20 deploys, 20 migration runs, 20 certificate renewals. This plan neutralises most of that cost with three rules:

1. **Nothing hotel-specific is ever hard-coded.** All per-hotel variation lives in `.env`, `config/doba.php`, the database, and an optional theme directory. The application code is byte-identical across every install.
2. **All installs share one git repository and one release artifact.** Deploy = pull the same tag on every host, then run migrations. A single Ansible playbook (or a shell loop over a hosts file) does all installs in one command.
3. **Per-hotel customisation is data and theme, not a code fork.** The moment someone patches PHP for one hotel, the model breaks. If a hotel needs something unique, it becomes a config flag or a theme override — never a branch.

---

## 2. Server & runtime requirements

- PHP 8.3+ with `pdo_mysql`, `pdo_sqlite`, `mbstring`, `intl`, `gd` (or `imagick`), `zip`, `curl`, `openssl`
- Apache 2.4 with `mod_rewrite`, `mod_headers`, `mod_deflate`, `mod_ssl`, `mod_proxy`, `mod_proxy_fcgi`; PHP via **php-fpm** (not `mod_php` — FPM gives per-hotel pools and isolation)
- Composer 2, Node 20 (build-time only, assets compiled once in CI, not on the hotel's server)
- Cron: one entry per install running Laravel's scheduler
- Supervisor (or `systemd`) for one queue worker per install
- Certbot for TLS

**Redis is optional.** With per-hotel installs the default `database` cache/queue/session drivers are fine and keep dependencies minimal. Add Redis only for the busiest installs.

---

## 3. Repository & directory layout

```
doba/
├── app/
│   ├── Console/Commands/           # SyncIcalCommand, ReleaseExpiredHoldsCommand, ...
│   ├── Domain/
│   │   ├── Availability/           # AvailabilityService, calendar assembly
│   │   ├── Pricing/                # RateEngine, restrictions, promo codes
│   │   ├── Booking/                # BookingService, holds, state machine
│   │   ├── Channels/               # iCal import/export, channel-manager adapters
│   │   └── Payments/               # Gateway contract + Stripe/PayPal adapters
│   ├── Filament/                   # Admin panel resources, pages, widgets
│   ├── Installer/                  # wizard steps, requirement checks, .env writer
│   ├── Http/
│   │   ├── Controllers/            # Public site + booking funnel
│   │   ├── Controllers/Api/V1/     # thin layer over the same domain services
│   │   ├── Resources/              # API response shapes (single source for OpenAPI)
│   │   └── Middleware/             # SetLocale, ResolveHotelSettings, EnsureInstalled,
│   │                               # AuthenticateApiClient, CheckScope, Idempotency
│   ├── Models/
│   ├── Notifications/              # Booking mails (localised)
│   └── Providers/
├── config/
│   └── hotel.php                   # ← the per-hotel switchboard
├── database/migrations/
├── lang/                           # en, de, pl, uk, ru, ...
├── resources/
│   ├── views/
│   │   ├── themes/default/         # base theme
│   │   └── themes/<hotel-slug>/    # optional per-hotel overrides
│   └── css|js/
├── storage/app/public/             # per-install uploads (images, invoices)
├── deploy/
│   ├── apache/vhost.conf.tpl
│   ├── ansible/                    # inventory = list of hotel installs
│   └── deploy.sh
└── tests/
```

### Theme resolution

A `ThemeServiceProvider` prepends `resources/views/themes/{THEME}` to the view finder. Any Blade file the hotel wants to change is copied into its theme folder and edited there; everything else falls through to `default`. One `THEME=alpenhof` line in `.env` switches it. Colours, fonts, logo and hero images are *settings*, not theme files — only structural layout changes justify a theme override.

---

## 4. Per-hotel configuration

### `.env` (the things that differ per install)

```ini
APP_NAME="Hotel Alpenhof"
APP_URL=https://alpenhof.example
APP_LOCALE=de
APP_FALLBACK_LOCALE=en

DB_CONNECTION=mysql          # or: sqlite
DB_DATABASE=alpenhof

DOBA_THEME=alpenhof
DOBA_CURRENCY=EUR
DOBA_TIMEZONE=Europe/Berlin
DOBA_LOCALES=de,en,pl,uk
DOBA_CHECKIN_FROM=15:00
DOBA_CHECKOUT_UNTIL=11:00

PAYMENT_GATEWAY=stripe       # stripe | paypal | none
STRIPE_KEY=...
STRIPE_SECRET=...
STRIPE_WEBHOOK_SECRET=...

MAIL_MAILER=smtp
MAIL_FROM_ADDRESS=booking@alpenhof.example
```

### `config/doba.php` (feature flags, identical file everywhere)

```php
return [
    'theme'    => env('DOBA_THEME', 'default'),
    'currency' => env('DOBA_CURRENCY', 'EUR'),
    'locales'  => explode(',', env('DOBA_LOCALES', 'en')),
    'features' => [
        'online_payment'   => env('FEATURE_PAYMENT', true),
        'deposit_only'     => env('FEATURE_DEPOSIT_ONLY', true),
        'ota_sync'         => env('FEATURE_OTA_SYNC', false),
        'promo_codes'      => env('FEATURE_PROMO', true),
        'extras'           => env('FEATURE_EXTRAS', true),   // breakfast, parking, spa
        'reviews'          => env('FEATURE_REVIEWS', false),
        'multi_property'   => false,                          // always false in this model
    ],
    'booking' => [
        'hold_minutes'        => 20,   // cart hold before release
        'max_nights'          => 30,
        'booking_window_days' => 540,  // how far ahead guests can book
        'cancellation_policy' => 'flexible',
    ],
];
```

**Rule:** anything a hotelier should be able to change themselves (address, phone, policies, texts, colours, images, room descriptions) belongs in the **database** and is edited in the admin panel. `.env`/`config` hold only what a developer sets at install time.

The `settings` table (key/value with a `group` column, cached) covers branding, contact details, social links, analytics IDs, policy texts, and the map coordinates. `ResolveHotelSettings` middleware injects them into every view via a `$hotel` view-shared object.

---

## 5. Database schema

Written to run unchanged on MySQL 8 and SQLite 3.35+. Constraints of the portable subset: no stored procedures, no `ENUM` (use `string` + a PHP enum cast), no fulltext search (use `LIKE` or an external index), `JSON` columns are stored as `TEXT` on SQLite and cast in the model, and every `DATE` is a plain `date` column compared as `Y-m-d` strings.

> **Money is stored as integer minor units (cents), not `DECIMAL`.** SQLite has no DECIMAL type — `decimal(10,2)` gets NUMERIC affinity and non-integer values land in 8-byte floats, so a `SUM()` over nightly prices or a `total = paid_amount` comparison can disagree between the two engines while Laravel's `decimal:2` cast hides it on single-row reads. Every money column below (`price`, `total`, `amount`, …) is a `bigInteger` of cents, formatted only at the edges by a `Money` value object. This is the single most important portability rule in the schema.

### Core inventory

**`room_types`** — the sellable unit (a *category*, not a physical room)
`id`, `code` (internal, stable, non-routing), `base_occupancy`, `max_occupancy`, `max_adults`, `max_children`, `extra_adult_price`, `extra_child_price`, `size_sqm`, `bed_setup`, `default_rate`, `total_units` (how many physical rooms of this type exist), `sort_order`, `is_active`, timestamps

**`room_type_translations`** — `id`, `room_type_id`, `locale`, **`slug`**, `name`, `short_description`, `description`, `meta_title`, `meta_description`; unique on (`room_type_id`, `locale`) and on (`locale`, `slug`)

**The routable slug lives in the translation table, not the parent.** `/de/zimmer/doppelzimmer` and `/en/rooms/double-room` must both resolve to the same room type; a single slug column on the parent makes that impossible. Same for `pages`. The path segment itself (`zimmer` vs `rooms`) is a `lang/` string, resolved by a route macro.

**`rooms`** *(optional, phase 2)* — physical rooms for assignment and housekeeping: `id`, `room_type_id`, `number`, `floor`, `notes`, `status` (`clean|dirty|out_of_order`)

**`amenities`** + **`amenity_translations`** + **`amenity_room_type`** — wifi, balcony, minibar, …

**`media`** — polymorphic images: `id`, `mediable_type`, `mediable_id`, `path`, `disk`, `alt` (JSON per locale), `sort_order`, `is_cover`

### Availability & rates — the heart of the system

**`availability`** — one row per room type per date. This single table drives the calendar, the price and every restriction.

| column | type | meaning |
|---|---|---|
| `id` | bigint | |
| `room_type_id` | FK | |
| `date` | date | the *night* being sold |
| `allotment` | smallint | units available for sale that night (starts at `total_units`) |
| `booked` | smallint | units consumed by live bookings (derived — see below) |
| `held` | smallint | units consumed by unexpired checkout holds (derived — see below) |
| `price` | bigint nullable (cents) | overrides the rate plan for that night |
| `min_stay` | tinyint default 1 | minimum nights, evaluated **on the arrival date** |
| `max_stay` | tinyint nullable | maximum nights, evaluated on the arrival date |
| `min_stay_through` | tinyint nullable | rarer "must span N nights through this date" variant |
| `closed` | boolean | stop-sell for that night |
| `closed_to_arrival` | boolean | guest may not check in on this date |
| `closed_to_departure` | boolean | guest may not check out on this date |

Unique index on (`room_type_id`, `date`); index on `date`.

**`booked` and `held` are caches, never the source of truth.** The ground truth is `booking_room_nights` joined to live bookings, plus unexpired `booking_holds`. Keeping them as separate columns means a stuck hold can never be mistaken for a real booking, and a nightly `availability:reconcile` command can recompute both from ground truth and alert on any delta. A counter that drifts *high* silently stops selling rooms and is otherwise invisible — this reconciliation is what catches it.

Rows are pre-generated **`booking_window_days + max_nights + 1`** days ahead by a nightly `availability:extend` command, so a missing row is always an error, never "assume available". (The naive `booking_window_days` is wrong: a stay starting on the last bookable day still needs rows for its nights *and* its checkout date.) This makes every availability query a simple aggregate over a date range — fast on both engines and trivial to reason about.

**Integrity constraint:** `CHECK (booked >= 0 AND held >= 0 AND booked + held <= allotment)`. Three caveats, because this net is the last line of defence: Laravel's schema builder has no CHECK API, so it needs raw DDL; SQLite has no `ALTER TABLE ADD CONSTRAINT`, so it must be in the original `CREATE TABLE` **and re-emitted whenever a later migration uses `->change()`** on this table (Laravel rebuilds the table and silently drops it); and MySQL only *enforces* CHECK from 8.0.16+ (earlier versions parse and ignore it). CI asserts the constraint still exists on both engines.

**`rate_plans`** — `id`, `code`, `type` (`standard|non_refundable|early_bird|long_stay|package`), `adjustment_type` (`percent|fixed`), `adjustment_value`, `min_nights`, `max_nights`, `min_days_before_arrival`, `max_days_before_arrival`, `includes_breakfast`, `cancellation_hours`, `refundable`, `is_active`, `valid_from`, `valid_to`, `priority`
**`rate_plan_translations`** — name + description + policy text per locale
**`rate_plan_room_type`** — which plans apply to which room types

**`seasons`** — `id`, `name`, `starts_on`, `ends_on`, `priority`
**`season_rates`** — `season_id`, `room_type_id`, `weekday_mask` (bitmask Mon–Sun), `price` — the bulk way to set prices without touching 540 `availability` rows

### Bookings

**`bookings`**
`id`, `reference` (unique, human-readable e.g. `ALP-2026-0412`), `manage_token` (random 40 chars — authorises the guest's self-service `/booking/manage` link without a login), `status` (`pending|confirmed|checked_in|checked_out|cancelled|no_show`), `source` (`direct|booking_com|airbnb|expedia|phone|walk_in`), `channel_reference`, `check_in`, `check_out`, `nights`, `adults`, `children`, `children_ages` (JSON), `currency`, `subtotal`, `extras_total`, `discount_total`, `tax_total`, `city_tax`, `total`, `deposit_due`, `paid_amount`, `balance_due`, `promo_code_id`, `locale` (guest's language — all later mail uses it), `guest_id`, `guest_notes`, `internal_notes`, `cancellation_reason`, `cancelled_at`, `confirmed_at`, `ip_address`, `user_agent`, timestamps

**`booking_rooms`** — a booking may contain several room types
`id`, `booking_id`, `room_type_id`, `rate_plan_id`, `room_id` (nullable, assigned later), `adults`, `children`, `guest_name`, `price_total`, `cancellation_policy_snapshot` (the policy text in the guest's language, frozen at booking time — it sits here and not on `bookings` because policy belongs to the rate plan, and a booking may mix plans), `cancellation_hours_snapshot`, `refundable_snapshot`

**`booking_room_nights`** — the per-night price snapshot; never recompute a past booking's price
`id`, `booking_room_id`, `date`, `price`

**`booking_extras`** — `booking_id`, `extra_id`, `quantity`, `unit_price`, `total`, `applies_per` (`stay|night|person|person_night`)
**`extras`** + **`extra_translations`** — breakfast, airport transfer, parking, spa entry, cot, late checkout

**`booking_holds`** — the anti-overbooking mechanism
`id`, **`booking_id`** (FK — without it the release command cannot find the pending booking it must cancel), `session_id`, `room_type_id`, `date`, `units`, `expires_at`, `released_at` (nullable); index on `expires_at`

**`guests`** — `id`, `email`, `first_name`, `last_name`, `phone`, `country`, `address`, `city`, `postal_code`, `locale`, `marketing_consent`, `notes`, `stays_count`, `total_spent`
Deduplicated by lowercased email so repeat guests build a history.

**`booking_status_history`** — `booking_id`, `from_status`, `to_status`, `user_id` (nullable = system), `reason`, `created_at`. Every transition is written here; this is your audit trail when a guest disputes a cancellation.

### Payments

**`payments`** — `id`, `booking_id`, `gateway` (`stripe|paypal|manual|bank_transfer`), `gateway_payment_id`, `gateway_customer_id`, `type` (`deposit|balance|full|refund`), `status` (`pending|authorized|paid|failed|refunded|partially_refunded`), `amount`, `currency`, `fee`, `payload` (JSON, raw gateway response), `paid_at`, `refunded_at`, `idempotency_key` (unique)
**`invoices`** — `id`, `booking_id`, `number` (sequential per year, unique), `issued_at`, `pdf_path`, `net_total`, `gross_total`
**`invoice_lines`** — `invoice_id`, `description`, `quantity`, `unit_net`, `tax_rate`, `tax_amount`, `line_gross`. A single `tax_total` is not enough: DE/PL/UA invoices must show a **per-VAT-rate breakdown**, and accommodation is typically a reduced rate while breakfast, parking and spa are standard. `extras` therefore also needs a `tax_rate` column.

### Discounts

**`promo_codes`** — `id`, `code` (unique), `discount_type` (`percent|fixed|free_nights`), `value`, `min_nights`, `min_total`, `valid_from`, `valid_to`, `stay_from`, `stay_to`, `usage_limit`, `usage_count`, `per_guest_limit`, `room_type_ids` (JSON, null = all), `is_active`
**`promo_code_redemptions`** — `promo_code_id`, `booking_id`, `guest_id`, `redeemed_at`

### Channels / OTA

**`channels`** — one *account*, not one calendar: `id`, `name`, `type` (`ical|api`), `credentials` (JSON, encrypted), `last_synced_at`, `last_error`, `consecutive_error_count`, `is_active`
**`channel_room_type_mappings`** — `channel_id`, `room_type_id`, `external_room_id`, `import_url`, `export_token`, `last_synced_at`. Splitting this out now costs nothing and is mandatory the moment a tier-2 aggregator (one account, many room types) arrives; putting `room_type_id` on `channels` would duplicate credentials per room.
**`channel_bookings`** — raw imported events kept for reconciliation: `channel_id`, `external_uid`, `check_in`, `check_out`, `summary`, `raw`, `booking_id` (nullable), `imported_at`, `missing_since` (nullable — see the removal rule in §9)

### Content (CMS)

**`pages`** + **`page_translations`** — parent: `code` (internal), `template`, `is_published`, `sort_order`, `show_in_menu` / per locale: **`slug`** (unique on `locale`+`slug`), `title`, `body` (HTML), `meta_title`, `meta_description`, `og_image`
**`menu_items`** — `parent_id`, `page_id` or `url`, `sort_order`, `locale` (nullable = all), `target`
**`galleries`** + **`gallery_translations`** — just a named container; the images themselves are `media` rows attached to the gallery. (No separate `gallery_images` table — one polymorphic media table for the whole system, or you will end up writing image handling twice.)
**`faqs`** + **`faq_translations`**
**`redirects`** — `from`, `to`, `code` — essential when a hotel migrates from an old site
**`enquiries`** — the contact form has to land somewhere: `name`, `email`, `phone`, `locale`, `subject`, `message`, `check_in`/`check_out` (nullable, for "request a quote"), `status` (`new|read|replied|spam`), `replied_by`, `ip_address`, `created_at`
**`reviews`** + **`review_responses`** *(only when `FEATURE_REVIEWS` ships — phase 6)* — `booking_id`, `guest_id`, `rating`, `title`, `body`, `locale`, `is_published`, `published_at`. Until this table exists, keep the flag and the T+1 review-request mail switched off rather than pointing guests at nothing.

### Admin & system

**`users`** — admin/staff accounts (`name`, `email`, `password`, `locale`, `two_factor_secret`)
**`roles`** / **`permissions`** — via `spatie/laravel-permission`. Roles: **Owner** (everything incl. billing/settings), **Manager** (rates, bookings, content), **Reception** (bookings, check-in/out, guests — no rate or settings access), **Editor** (CMS only)
**`settings`** — `group`, `key`, `value` (JSON), `is_translatable`
**`activity_log`** — via `spatie/laravel-activitylog`; who changed which rate, when
**`jobs` / `failed_jobs` / `cache` / `sessions`** — Laravel defaults on the `database` driver

### API & integration

**`api_clients`** — one row per external consumer (a channel manager, a travel agency, the hotel's own mobile app)
`id`, `name`, `contact_email`, `key_id` (public, sent as `X-Api-Key-Id`), `secret_hash` (Argon2 — the secret is shown **once**, at creation, and never retrievable), `scopes` (JSON), `mode` (`live|sandbox`), `allowed_ips` (JSON, nullable), `rate_limit_per_minute`, `last_used_at`, `expires_at`, `revoked_at`, timestamps

**`api_request_logs`** — `api_client_id`, `method`, `path`, `status`, `duration_ms`, `idempotency_key`, `request_id`, `ip`, `created_at`; pruned after 90 days. This is what you read when a partner says "your API rejected my booking" — without it, every integration dispute is unresolvable.

**`idempotency_keys`** — `key` (unique), `api_client_id`, `request_hash`, `response_status`, `response_body`, `locked_at`, `expires_at`. A replayed `POST /bookings` returns the *original* response instead of creating a second booking.

**`webhook_endpoints`** — `api_client_id`, `url`, `events` (JSON), `signing_secret`, `is_active`, `consecutive_failures`, `disabled_at`
**`webhook_deliveries`** — `webhook_endpoint_id`, `event`, `payload`, `attempt`, `response_status`, `response_body`, `delivered_at`, `next_retry_at`. Replayable from the admin panel.

**`personal_access_tokens`** — Laravel Sanctum, for first-party clients (the hotel's own mobile app or a future admin SPA). Separate from `api_clients`, which is for third parties.

### Installation state

**`installation`** — a single row: `installed_at`, `installed_version`, `installer_locked`, `install_token_hash`, `steps_completed` (JSON). Belt to the `storage/installed.lock` file's braces (see §16) — the file can be deleted by accident or by a careless deploy script; the database row cannot.

### Translation strategy — two layers

1. **Interface text** (buttons, labels, validation, emails) → `lang/{locale}/*.php` files, versioned in git, identical across installs.
2. **Content text** (room names, page bodies, policies, extras) → `*_translations` tables edited in the admin panel by the hotelier.

Never mix them. Interface strings in the database become a translation nightmare; content in PHP files means the hotelier can't edit their own site.

---

## 6. Availability & booking engine

### Search (`GET /booking/search?check_in=&check_out=&adults=&children=`)

Two date sets, and confusing them is the classic booking-engine bug:

- **`N` = the nights sold** = `[check_in … check_out − 1]` — inventory, price and `closed` apply here.
- **`B` = the boundary rows** = `check_in` (for `closed_to_arrival`, `min_stay`, `max_stay`) and `check_out` (for `closed_to_departure`).

So the query loads `[check_in … check_out]` **inclusive** — the checkout date's row is needed for CTD even though no inventory is consumed on it.

1. Reject if `check_in >= check_out`, `check_in < today` (in `DOBA_TIMEZONE`), `nights > max_nights`, or `check_in` beyond `booking_window_days`.
2. For each active room type, load `availability` rows for `[check_in … check_out]` (one query for all types).
3. A room type is **bookable** only if:
   - for every date in `N`: `closed = false` **and** `allotment − booked − held >= 1`
   - `closed_to_arrival = false` on the `check_in` row
   - `closed_to_departure = false` on the `check_out` row — *never* check `closed` or allotment on that row
   - `nights >= min_stay` and `nights <= max_stay` **on the arrival row only**, plus any `min_stay_through` on nights in `N`
   - occupancy fits: `adults + children <= max_occupancy`, `adults <= max_adults`, `children <= max_children`

   **Why min-stay is evaluated on the arrival date:** that is what ARI and every OTA mean by it, and what a hotelier means by "3-night minimum on Saturdays". Requiring `nights >= max(min_stay)` across the whole stay would block a Fri–Sun two-night booking that Booking.com accepts — quietly losing the hotel revenue with no error anywhere.
4. Price = for each applicable rate plan, sum the per-night price and apply the plan's adjustment; the nightly price is resolved as
   **`availability.price` → season rate matching the date and weekday → `room_types.default_rate`**.
5. Return each room type with its cheapest and its full set of rate plans. Show "only N left" from **`allotment − booked`** (confirmed only) — counting holds lets anyone with a script manufacture false scarcity on your own site.
6. **If no single room type fits the party**, compose a multi-room offer: greedily fill with the cheapest combination of bookable types whose occupancy sums to the party size (`booking_rooms` already models this). A family of five silently getting "no availability" because every room sleeps four is a large and completely invisible source of lost bookings.

### The calendar widget

- Public: a two-month date-range picker. On load it fetches `GET /api/calendar?from=&to=&room_type=` returning, per date: `available` (bool), `price` (from), `min_stay`, `cta`, `ctd`. Unavailable and closed-to-arrival dates are disabled; each date cell shows the "from" price. Rendered with Alpine.js against a small JSON payload — no heavy calendar library.
- Admin: a month grid, room types as rows and dates as columns, showing allotment / booked / price. Cells are drag-selectable; a bulk-edit panel then sets price, min-stay, or stop-sell across the selection and weekday filter. This screen is what a hotelier actually lives in — budget real time for it.

### Preventing double bookings

This is the one place where correctness cannot be compromised.

```php
DB::transaction(function () use ($ids, $checkIn, $checkOut, $units) {
    // NOTE: nights only — the checkout row consumes no inventory.
    $rows = Availability::whereIn('room_type_id', $ids)
        ->whereBetween('date', [$checkIn, $checkOut->subDay()])
        ->lockForUpdate()          // MySQL: SELECT ... FOR UPDATE
        ->get();

    foreach ($rows as $row) {
        if ($row->allotment - $row->booked - $row->held < $units) {
            throw new NoAvailabilityException($row->date);
        }
    }

    $booking = Booking::create([...]);            // status = pending
    // ... booking_rooms, booking_room_nights, extras

    Availability::whereIn('id', $rows->pluck('id'))->increment('held', $units);
    BookingHold::createForNights($booking, $rows, $units, now()->addMinutes($holdMinutes));

    return $booking;
}, attempts: 3);
```

- **MySQL:** `lockForUpdate()` takes row locks on the exact `availability` rows. Two concurrent attempts on the same night serialise; the loser gets `NoAvailabilityException` and a friendly "just taken" message.
- **SQLite — read this carefully, the obvious approach is broken.** `lockForUpdate()` is a no-op, so the guarantee must come from the engine. But **Laravel does not open SQLite transactions as `BEGIN IMMEDIATE`** — it calls `PDO::beginTransaction()`, which issues a plain deferred `BEGIN`. The transaction therefore starts as a *reader* (the SELECT) and only upgrades to a writer at the `increment`. In WAL mode, if any other connection committed since that read snapshot, the upgrade fails **immediately with `SQLITE_BUSY` and the `busy_timeout` is never consulted** — the busy handler is not invoked for read-to-write upgrades. You get sporadic driver errors, not serialisation.
  **The fix is mandatory, not optional:** register a `SQLiteConnection` subclass via `Connection::resolverFor('sqlite', …)` that issues `BEGIN IMMEDIATE` so the write lock is taken up front, and set `journal_mode=WAL`, `busy_timeout=5000`, `foreign_keys=ON` on connect. Then SQLite's single-writer rule genuinely serialises the transaction above — correct, just less concurrent. Fine for a hotel taking a few bookings an hour; wrong for one taking a few per second.
- **Belt and braces on both:** the `CHECK` constraint from §5, plus a unique index on `bookings.reference`. If the logic ever has a hole, the database refuses the write rather than overselling.

### Holds, and the payment race that actually bites

When a guest reaches the payment step, `held` is incremented and a `booking_holds` row is written with `expires_at = now + hold_minutes`. `ReleaseExpiredHoldsCommand` runs every minute, decrements `held`, and cancels the linked `pending` booking.

**The failure mode to design for:** a payment webhook can arrive *after* the hold expired. Stripe retries failed webhooks for up to three days; a 3-D Secure challenge, a bank app switch, or a brief outage on your side all put a successful payment on the far side of a 20-minute hold. Naively confirming on `payment_intent.succeeded` then flips a `cancelled` booking to `confirmed` with **no inventory reserved**, possibly after the room was resold — money taken, room gone.

The rules that close it:

1. Stamp the hold when the **PaymentIntent is created**, not when the summary page loads, and set `hold_minutes` comfortably longer than a 3-D Secure round trip (20 minutes is a reasonable floor).
2. The webhook handler **re-acquires inventory inside the same `lockForUpdate` transaction** before confirming — it never trusts that the hold survived.
3. If inventory is genuinely gone, do not confirm: issue an automatic full refund, mail the guest an apology with alternative dates, and page the hotel. A refunded guest is recoverable; an overbooked one at 11pm is not.
4. `confirmed_at` is set only inside that transaction; the handler is idempotent on `gateway_payment_id`, so retries are free.

### Booking state machine

```
pending ──payment ok──▶ confirmed ──arrival──▶ checked_in ──departure──▶ checked_out
   │                        │                       │
   └──hold expired──▶ cancelled ◀──guest/staff──────┘   (also: no_show)
```

Every transition goes through `BookingService`, writes `booking_status_history`, and fires an event.

**Inventory is released by the status being entered, not by the status being left.** "Only `confirmed → cancelled` releases inventory" is a trap: a staff member cancelling a `pending` booking from the admin panel (which §12 offers) would leak that unit forever. Define two sets — *consuming* (`pending`, `confirmed`, `checked_in`, `checked_out`, `no_show`) and *non-consuming* (`cancelled`) — and let `BookingService` diff them on every transition. One rule, no leaks.

---

## 7. Rates & pricing rules

Resolution order when quoting a night, first match wins:

1. `availability.price` for that exact date (manual override / channel-manager push)
2. `season_rates` row matching the date **and** the weekday bitmask, highest `priority`
3. `room_types.default_rate`

Then, in order:
4. Rate-plan adjustment (`-10%` non-refundable, `+15%` with breakfast, …), eligibility checked against `min_nights`, `max_nights`, and days-before-arrival window
5. Occupancy surcharge: `extra_adult_price × (adults − base_occupancy)` and `extra_child_price` per child, per night, capped at `max_occupancy`
6. Extras (per stay / per night / per person / per person-night)
7. Promo code (percent, fixed, or free-nights — cheapest night discounted first), validated against usage limits and stay window
8. Taxes: VAT at the configured rate, plus per-person-per-night city tax (many European municipalities require it shown separately)

**The computed price is frozen into `booking_room_nights` at booking time.** Rates change; a confirmed booking's price never does.

### Cancellation policies

Attached to the rate plan (`refundable`, `cancellation_hours`). When a booking is cancelled, `BookingService` computes the refund: full refund outside the window, forfeit the deposit inside it, nothing for non-refundable plans. The policy text is snapshotted onto **`booking_rooms`** (not `bookings`) in the guest's language at booking time — per room, because a booking may mix a refundable and a non-refundable plan, and because a later dispute must be settled by the wording the guest actually agreed to, not by today's version of the policy.

---

## 8. Payments

A `PaymentGateway` interface with `StripeGateway`, `PayPalGateway`, and `ManualGateway` (bank transfer / pay-on-arrival) implementations, selected by `PAYMENT_GATEWAY`.

**Flow (Stripe, deposit mode):**

1. Guest confirms the summary → hold created, booking `pending`
2. Server creates a Stripe **PaymentIntent** for `deposit_due`, with `metadata.booking_reference` and an `idempotency_key` derived from the booking — a double-clicked button cannot charge twice
3. Stripe Elements collects the card on your domain (SCA/3-D Secure handled by Stripe)
4. **The webhook is the source of truth, not the browser redirect.** `payment_intent.succeeded` → re-acquire inventory (§6), write `payments`, set booking `confirmed`, convert the hold, send confirmation mail. Webhook signature verified; the handler is idempotent on `gateway_payment_id`.
5. The return page polls booking status for a few seconds and shows the confirmation — but never itself confirms the booking.

**Later charges need explicit setup.** The balance charge before arrival and the no-show charge are **off-session** payments. In the EU they only work if the original PaymentIntent was created with `setup_future_usage` (or a separate SetupIntent), the payment method was saved to a Stripe Customer, and a mandate was shown and stored at booking time. Off-session charges also fail with `authentication_required` for a meaningful share of cards — the job must handle that by emailing the guest a link to complete the payment on-session, not by silently retrying. Skipping this is the single most common reason "charge the balance automatically" doesn't work in production in Europe.

**Refunds** from the admin panel, partial or full, writing a `refund` row and recomputing `paid_amount` / `balance_due`.

**PCI:** card data never touches your server — Stripe Elements / PayPal SDK only. That keeps you in SAQ-A, the lightest compliance tier. Do not build a card form.

Invoices are generated as PDFs with **Dompdf**, numbered sequentially per year per install, and attached to the confirmation mail where the hotel requires it. Do *not* use `spatie/laravel-pdf` here — it renders via Browsershot, which needs headless Chromium and Node on the machine generating the PDF. Across twenty installs that means twenty Chromium footprints and a new class of memory and zombie-process incidents, and it contradicts §2's "Node is build-time only".

---

## 9. Channel manager / OTA sync

Two tiers — build tier 1 first, it covers most independent hotels.

**Tier 1 — iCal, two-way (phase 3)**
- **Export:** `GET /ical/{room_type}/{token}.ics` publishes confirmed bookings as `VEVENT` blocks (dates only, no guest PII). Booking.com, Airbnb and Vrbo subscribe to this URL.
- **Import:** `SyncIcalCommand` runs every 15 minutes per mapping, fetches each `import_url`, parses events, and writes `channel_bookings`. New events create a `source = booking_com` booking and consume `availability`. Events are matched on `external_uid` so re-imports are idempotent.
- **Removals are the dangerous direction, and need a guard.** "Event vanished → release the room" turns any feed failure into an instant fire sale: a truncated response, a rotated feed URL, a 200 with an empty body, or a partial parse would release *every* OTA-held night at once and resell them — precisely the overbooking this section exists to prevent. The rule instead: only consider removals from a **fully parsed** feed whose event count is plausible against the last sync; stamp `missing_since` and require the event to be absent from **three consecutive** syncs; and **never auto-release a stay starting within 7 days** — flag it for staff review. Releasing a room late costs one night; releasing it wrongly costs a guest standing at the desk.
- **Known limitation, state it to the hotelier plainly:** iCal syncs *availability only*, with a 15–60 minute lag, and cannot push prices. Two guests can still book the last room on two channels inside that window. It is the pragmatic 90% solution, not a guarantee.
- **Alert on staleness.** `consecutive_error_count > 2` or `last_synced_at` older than an hour must page someone. A silently dead sync looks exactly like a quiet week.

**Tier 2 — real channel manager API (phase 5, only if demand justifies it)**
Integrate one aggregator (Channex, Beds24 or SiteMinder) rather than each OTA separately. They speak ARI (Availability, Rates, Inventory) push: you send rate and allotment updates on change, they fan out to every OTA and push bookings back to your webhook. `ChannelAdapter` contract keeps this behind the same interface as iCal.

---

## 10. Multi-language

**Locale resolution** (`SetLocale` middleware), in order: URL prefix → session → `Accept-Language` → `APP_LOCALE`.

**URL structure:** `https://hotel.example/de/zimmer/doppelzimmer`. The default locale may optionally be prefix-less. Every page emits `<link rel="alternate" hreflang="…">` for all configured locales plus `x-default`, and translated slugs are stored per locale so `/de/zimmer/…` and `/en/rooms/…` both resolve.

**Coverage:** UI strings in `lang/`; content in `*_translations` tables; emails rendered in `booking.locale`; dates, times and currency formatted with `intl` per locale; admin panel available in the staff member's own language.

**Fallback:** missing content translation falls back to `APP_FALLBACK_LOCALE`, and the admin panel shows a per-locale completeness indicator so the hotelier can see what still needs translating.

For a Ukrainian/Polish/German market, plan for `uk`, `pl`, `de`, `en` from day one — including Cyrillic-safe slugs (transliterate on generation) and `utf8mb4_unicode_ci` collation on MySQL.

---

## 11. Public site — pages & routes

```
GET  /{locale?}                          home (hero, search widget, featured rooms, teasers)
GET  /{locale}/rooms                     room type list with "from" prices
GET  /{locale}/rooms/{slug}              room detail: gallery, amenities, rate plans, availability calendar
GET  /{locale}/booking/search            results for dates + guests
GET  /{locale}/booking/checkout          guest details, extras, promo code, policy consent
POST /{locale}/booking                   create hold + pending booking
GET  /{locale}/booking/pay/{ref}         payment step
GET  /{locale}/booking/confirmation/{ref}
GET  /{locale}/booking/manage/{ref}/{token}   view / cancel own booking (no login)
GET  /{locale}/{page-slug}               CMS pages: about, spa, restaurant, location, offers
GET  /{locale}/contact  + POST           enquiry form (honeypot + rate limit)
GET  /sitemap.xml, /robots.txt, /ical/{room_type}/{token}.ics
POST /webhooks/stripe  |  /webhooks/paypal  |  /webhooks/channel

GET  /install/*                          first-run wizard — 404 once installed (§16)
     /api/v1/*                           public/partner API (§17)
GET  /api/docs                           OpenAPI documentation
GET  /up                                 health check for uptime monitoring & deploy verification
```

**SEO, which is half the point of a direct-booking site:** server-rendered HTML, per-locale meta from the translation tables, `Hotel` + `Room` + `Offer` JSON-LD schema, canonical URLs, `hreflang`, XML sitemap regenerated nightly, responsive images (WebP, `srcset`), Core Web Vitals budget. The whole reason a hotel wants this site is to stop paying Booking.com 15–18% commission — if it doesn't rank and doesn't convert, it has no purpose.

---

## 12. Admin panel (Filament)

**Dashboard** — arrivals today, departures today, in-house count, occupancy % this month, revenue this month vs last year, pending payments, unread enquiries, sync errors.

**Bookings** — list with filters (status, source, date range, room type), detail view with full guest and payment history, actions: confirm, cancel with refund, check in, check out, mark no-show, resend confirmation, edit dates (re-validating availability), add extras, charge balance, refund.

**Calendar** — the month grid described in §6: allotment, bookings and prices per room type per date, drag-select bulk editing, stop-sell toggles.

**Rates** — room types, rate plans, seasons, season rates (bulk editor: date range + weekday filter + room types → price/min-stay/closed).

**Rooms & content** — room types with per-locale tabs, amenities, extras, galleries, CMS pages, menus, FAQs, redirects.

**Guests** — profiles, stay history, lifetime value, GDPR export and erasure.

**Enquiries** — contact-form submissions with status (`new/read/replied/spam`), assignment and reply-by-email. The dashboard's "unread enquiries" counter reads from here.

**Marketing** — promo codes with redemption stats.

**Channels** — connected calendars, last sync time, errors, manual re-sync button.

**API access** — create and revoke API clients, choose scopes, set the IP allowlist and rate limit, issue sandbox keys, and read the request log. The secret is displayed exactly once, at creation, with a "copy" button and an explicit warning; there is no "show secret" action anywhere, because there is no stored plaintext to show.

**Webhooks** — registered endpoints per client, per-event delivery log with request and response bodies, manual replay of a failed delivery, and a "send test event" button. When a partner integration breaks, this screen is the difference between a five-minute answer and a three-day email thread.

**Reports** — occupancy, ADR (average daily rate), RevPAR, revenue by source and room type, cancellation rate, lead time distribution; CSV export.

**Settings** — branding (logo, colours, fonts), contact details, policies, check-in/out times, taxes, mail templates, locales, staff users and roles.

Access is role-gated per §5; every write is captured by the activity log.

---

## 13. Emails & notifications

All Markdown-mailable, rendered in the guest's locale, brandable per hotel via the settings-driven layout.

| Trigger | Recipient |
|---|---|
| Booking confirmed | Guest (+ PDF invoice where required) + hotel inbox |
| Payment received / failed | Guest |
| Cancellation confirmed (with refund amount) | Guest + hotel |
| Pre-arrival reminder (T−3 days: directions, check-in time, extras upsell) | Guest |
| Post-stay thank-you / review request (T+1 day) | Guest |
| Balance due reminder | Guest |
| Daily arrivals digest | Hotel |
| Sync failure / payment webhook failure | Admin |

All of these are queued jobs, not inline sends — a slow SMTP server must never delay a booking confirmation page. Scheduled ones run from the Laravel scheduler.

---

## 14. Security, privacy, reliability

- **Reservation of truth:** payments confirmed by webhook only; all money handlers idempotent
- HTTPS enforced, HSTS, CSP, `X-Frame-Options`, secure + `SameSite=Lax` cookies
- Rate limiting on search, booking creation, contact form and login; honeypot + timing check on public forms
- **API surface (§17):** secrets stored Argon2-hashed and shown once; per-client scopes, IP allowlist, expiry and instant revocation; per-client rate limits with separate read/write buckets; every request logged with a request ID; inbound webhook signatures verified and replay-rejected on timestamp; outbound webhooks HMAC-signed per endpoint. The API is a *second* front door to the booking engine — it needs the same locking guarantees (it gets them by using the same services) and stricter authentication than the website
- **The installer is the largest attack surface the app ever has** (§16): file-token gate before step 1, self-locking on completion, `/install/*` returning 404 afterwards, and a CI test asserting a fresh clone cannot be re-installed over a populated database
- 2FA available for admin users; `admin` routes optionally IP-restricted per hotel
- **GDPR:** explicit consent checkbox for marketing (separate from booking terms), privacy policy per locale, guest data export and erasure actions in the admin panel, configurable retention (e.g. anonymise guest PII 24 months after checkout, keep booking financials), cookie banner only if analytics are enabled
- Encrypted `channels.credentials`; no card data stored, ever
- **Backups:** `spatie/laravel-backup` per install → nightly DB + uploads to off-site S3-compatible storage (Hetzner Storage Box / Backblaze B2), 30-day retention, **restore tested quarterly** — an untested backup is a rumour
- **Mail deliverability per hotel domain: SPF, DKIM and DMARC records, set up during onboarding and verified before launch.** This — not the mail template — is what determines whether booking confirmations reach the guest's inbox or their spam folder. A confirmation that doesn't arrive generates a phone call, a doubt, and sometimes a chargeback. Use a transactional provider (Postmark, Mailgun, SES) rather than the host's `sendmail`, and monitor the bounce/complaint webhook.
- Monitoring: uptime check per domain, Sentry for exceptions, alert on failed jobs, on stale channel syncs, and on **any** `availability` drift found by `availability:reconcile` — in either direction. Overselling is loud; a counter stuck high silently stops the hotel selling rooms and nobody notices for a week.

---

## 15. Apache & per-domain deployment

### Directory convention on the host

```
/var/www/hotels/<slug>/           # one release directory per hotel install
    ├── current -> releases/2026-08-16-1/
    ├── releases/
    └── shared/{.env, storage/}
```

### Two vhost templates, in this order

The TLS vhost hard-references `/etc/letsencrypt/live/{{DOMAIN}}/fullchain.pem`. At the moment a *new* hotel's vhost is first enabled that file does not exist, `apachectl configtest` fails, and Apache refuses to reload — so `new-hotel.sh` cannot "render the vhost, then request the certificate" in that order. Provision in two steps: enable an **HTTP-only vhost** (serving the app and the ACME challenge path), run `certbot --apache -d {{DOMAIN}} -d www.{{DOMAIN}}`, then swap in the TLS template below and reload.

```apache
# --- canonical host: apex over TLS ---
<VirtualHost *:443>
    ServerName {{DOMAIN}}
    DocumentRoot /var/www/hotels/{{SLUG}}/current/public

    <Directory /var/www/hotels/{{SLUG}}/current/public>
        AllowOverride All
        Require all granted
        Options -Indexes +FollowSymLinks
    </Directory>

    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/{{SLUG}}.sock|fcgi://localhost"
    </FilesMatch>

    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/{{DOMAIN}}/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/{{DOMAIN}}/privkey.pem

    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"

    <IfModule mod_deflate.c>
        AddOutputFilterByType DEFLATE text/html text/css application/javascript application/json image/svg+xml
    </IfModule>

    ErrorLog  /var/log/apache2/{{SLUG}}-error.log
    CustomLog /var/log/apache2/{{SLUG}}-access.log combined
</VirtualHost>

# --- everything else redirects to the canonical host ---
<VirtualHost *:443>
    ServerName www.{{DOMAIN}}
    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/{{DOMAIN}}/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/{{DOMAIN}}/privkey.pem
    Redirect permanent / https://{{DOMAIN}}/
</VirtualHost>

<VirtualHost *:80>
    ServerName {{DOMAIN}}
    ServerAlias www.{{DOMAIN}}
    Redirect permanent / https://{{DOMAIN}}/
</VirtualHost>
```

`www` must **redirect**, not serve — an alias on the TLS vhost means every page exists at two URLs, which is exactly the duplicate-content problem §11's canonical tags are meant to avoid.

Each hotel gets its own **php-fpm pool** (own unix socket, own user) so one hotel's runaway request cannot starve another's, and a compromised install cannot read a neighbour's `.env`.

### Per-install cron & worker

```cron
* * * * * cd /var/www/hotels/<slug>/current && php artisan schedule:run >> /dev/null 2>&1
```

Scheduler entries: `holds:release` (every minute), `channels:sync` (every 15 min), `availability:extend` (nightly), `availability:reconcile` (nightly, alerts on any `booked`/`held` drift), `payments:charge-balances` (daily), `mail:pre-arrival` and `mail:post-stay` (daily), `backup:run` (nightly), `sitemap:generate` (nightly).

Supervisor runs `php artisan queue:work --sleep=1 --tries=3 --max-time=3600` per install.

### Adding a new hotel — the target is one command

```bash
./deploy/new-hotel.sh --slug=alpenhof --domain=alpenhof.example --db=mysql --locales=de,en,pl
```

which creates the directory structure and php-fpm pool, renders and enables the vhost, requests the certificate, creates the database (or the SQLite file), writes `.env` from a template, deploys the current release, runs `migrate --seed`, creates the owner account, and prints the login link. **Build this script in phase 1, before the second hotel exists.** Doing hotel #2 by hand guarantees hotel #7 is subtly different from hotel #3, and that divergence is what kills per-install models.

### Deploying a change to all hotels

`deploy/ansible/site.yml` over an inventory of installs: pull the tagged release, `composer install --no-dev -o`, `php artisan migrate --force`, `php artisan optimize` (one command — it runs the config/route/view/event caches; `config:cache route:cache …` chained on one line is not a valid invocation), `php artisan storage:link`, restart the FPM pool and queue worker, health-check the domain, roll back the symlink on failure. Run in serial batches so a bad migration cannot take down every hotel simultaneously.

---

## 16. First-run installation wizard

`new-hotel.sh` (§15) is the *developer's* path — SSH, one command, done. The wizard is the path for everyone else: a hotelier on shared hosting, a reseller setting up a client, or you setting up hotel #12 from a phone. Both must end in exactly the same state, and the wizard should ultimately be able to do everything the script does.

### Trigger and guard

`EnsureInstalled` middleware runs on every request:

- **Not installed** (no `storage/installed.lock` **and** no `installation` row) → every route redirects to `/install`.
- **Installed** → `/install/*` returns 404. Not a redirect, not a "already installed" page — 404, so a scanner learns nothing.

Two independent markers because each fails differently: a deploy script that rsyncs `storage/` can wipe the lock file, and a restored-from-backup database can carry a row for a filesystem that isn't set up. Install is complete only when both agree; if they disagree, the wizard opens in **repair mode** and shows what's missing rather than offering a fresh install that would drop live data.

**During installation the app is unprotected by definition.** Guard it: on first load the wizard writes a random token to `storage/install-token.txt` and demands it before step 1. Whoever runs the install must be able to read a file on the server — which is exactly the person who should be allowed to. On a fresh install the file is empty and the wizard prints the path; there is no way to skip it.

### Steps

**0 — Language.** Which language to run the wizard in. Trivial, and it sets the tone for a product whose whole pitch is multilingual.

**1 — Requirements check.** PHP version and every required extension, with a pass/fail per line and a copy-pasteable fix. Writability of `storage/`, `bootstrap/cache/`, `.env`. `mod_rewrite` active (tested by actually requesting a rewritten URL, not by trusting `phpinfo`). Outbound HTTPS working. **Blocking** — no "continue anyway" button. Every hour of support time saved here is worth ten of the wizard's development.

**2 — Database.** Choose SQLite (file path, created and tested for you) or MySQL (host, port, database, user, password). Connect, verify privileges, warn loudly if the schema is non-empty, then write `.env` and run `php artisan migrate --seed` **with live progress**, because on shared hosting this can take a minute and a silent white page is where people hit refresh and create two admin accounts.

**3 — Hotel identity.** Name, legal entity and address, phone, email, timezone, currency, default locale and the additional locales to enable, check-in/check-out times. Logo upload with the favicon and OG image derived automatically.

**4 — Owner account.** Name, email, password (strength-enforced), optional 2FA enrolment. This account gets the `Owner` role.

**5 — Mail.** SMTP or an API provider (Postmark / Mailgun / SES). **Send a real test mail and require the user to confirm they received it before continuing.** This is the single highest-value step in the wizard: unconfirmed mail configuration is the most common way a booking system appears to work perfectly and silently fails at the only moment that matters. The step also prints the exact SPF, DKIM and DMARC records for the chosen domain (§14) with a "check DNS now" button.

**6 — Payments.** Gateway choice, keys, test-vs-live toggle, deposit percentage, currency confirmation. Runs a live test call against the gateway. Prints the webhook URL and, for Stripe, registers the endpoint via the API so the user never has to find it in a dashboard. Skippable — "take bookings without online payment for now" is a legitimate starting configuration.

**7 — Rooms.** Either a quick inline builder (room type name, count, occupancy, base price — repeat) or "start from a template" (small B&B / city hotel / spa hotel). Whatever is entered here generates room types and the initial `availability` rows so the calendar is live immediately. A hotelier who reaches the dashboard and sees an empty calendar assumes it's broken.

**8 — Content & theme.** Theme choice, brand colours, and a set of starter CMS pages (Home, Rooms, Contact, Privacy, Terms, Imprint) pre-filled with sensible per-locale boilerplate — legally required pages in DE/PL/UA especially, which nobody remembers to create later.

**9 — Finish.** Writes `installation`, creates `storage/installed.lock` (mode 0400), deletes `install-token.txt`, runs `php artisan optimize` and `storage:link`, prints the cron line and the queue-worker command with a live check of whether each is running, then hands over a **post-install checklist**: TLS certificate, DNS records verified, backup destination configured, test booking made end-to-end.

### Design rules

- **Every step is resumable.** State goes to `installation.steps_completed` after each step, so a browser crash at step 7 resumes at step 7.
- **The wizard writes `.env` through a proper writer** that quotes values and preserves comments — never `file_put_contents` with string concatenation. A password containing `#` or a space breaks a naive writer and produces a broken install with a mystifying error.
- **Nothing the wizard does is wizard-only.** Every setting it writes is editable afterwards in Settings, and a `php artisan doba:install` non-interactive twin (taking a YAML file) does the same job for automated provisioning. The wizard is a front-end onto the same actions, so behaviour cannot diverge — this is what keeps hotel #12 identical to hotel #3.
- **The wizard is also the upgrade path.** After a deploy that adds migrations, `/install` in repair mode (owner login required) offers "run pending migrations" with the same live progress. Hoteliers on shared hosting have no shell.

---

## 17. Public API & webhooks

Two directions, and they're often confused:

- **Inbound** — something else calls *you*: a channel manager pushing rates and pulling bookings, a travel agency booking on your inventory, the hotel's own mobile app, a group website aggregating several hotels.
- **Outbound** — you call *them*: the OTA connectors in §9, the payment gateways in §8.

This section is the inbound half. §9 remains the outbound half; both sit behind the same service layer.

### The rule that matters most

**The API calls the same `AvailabilityService`, `RateEngine` and `BookingService` as the web funnel. No exceptions, no parallel implementation.** The controllers are thin translation layers over HTTP; all locking, restriction checks, pricing and state transitions live in the domain services from §6 and §7. The day the API grows its own booking logic is the day it oversells rooms the website thinks are free — and that bug will be found by a guest, not by you.

### Shape

- Base: `https://<hotel-domain>/api/v1` — versioned in the path, never a breaking change inside a version
- JSON only; `application/json` in, `application/json` out
- **All money as integer minor units plus an ISO currency code** (`{"amount": 12500, "currency": "EUR"}`) — same rule as the schema (§5), and it removes an entire category of integration bug
- Dates as `YYYY-MM-DD`; timestamps as ISO 8601 UTC. A booking's `check_in` is a *date*, never a timestamp
- Errors as RFC 9457 `application/problem+json` with a stable machine-readable `type`, a human `title`, and a `errors` map for validation. Partners integrate against `type`, so it must never change wording-for-wording
- Cursor pagination (`?cursor=…&limit=…`), because offset pagination over a table that's actively being written to skips and duplicates rows
- Every response carries `X-Request-Id`, echoed in `api_request_logs`, so a partner's bug report is one grep away from an answer

### Authentication

| Consumer | Mechanism |
|---|---|
| Third parties (channel manager, agency, group site) | `api_clients` key pair: `X-Api-Key-Id` + `X-Api-Secret`, secret stored Argon2-hashed, shown once at creation |
| Hotel's own mobile app / future admin SPA | Laravel Sanctum personal access tokens |
| Public availability widget on a partner's site | A read-only, scope-limited key that may be embedded — separate from anything that can write |

Scopes, granted per client: `availability:read`, `availability:write`, `rates:read`, `rates:write`, `bookings:read`, `bookings:write`, `bookings:cancel`, `guests:read`, `content:read`. Default to the minimum; `bookings:write` without `bookings:cancel` is a perfectly normal grant.

Also per client: optional IP allowlist, `expires_at`, instant revocation, and a **sandbox mode** whose keys hit the same code paths against a `mode=sandbox` flag — bookings are created, marked as test, and never touch real inventory or send real mail. Without a sandbox, every partner's first integration test is against your live calendar.

### Endpoints

```
# Discovery & inventory
GET    /api/v1/hotel                          identity, locales, currency, policies, check-in times
GET    /api/v1/room-types                     incl. translations, amenities, images, occupancy
GET    /api/v1/rate-plans
GET    /api/v1/extras

# Availability & pricing  (the read path most partners live on)
GET    /api/v1/availability?from=&to=&room_type=      per-date: available units, price, min_stay, cta, ctd
GET    /api/v1/search?check_in=&check_out=&adults=&children=
POST   /api/v1/quotes                         price a specific basket incl. extras, promo, taxes — no booking, no hold

# Bookings
POST   /api/v1/bookings                       creates hold + booking (Idempotency-Key required)
GET    /api/v1/bookings?updated_since=&status=   the pull endpoint a channel manager polls
GET    /api/v1/bookings/{reference}
PATCH  /api/v1/bookings/{reference}           dates, guests, extras — re-validated against availability
POST   /api/v1/bookings/{reference}/cancel    returns the computed refund
POST   /api/v1/bookings/{reference}/payments  register an external payment (agency paying by invoice)

# ARI push  (what a real channel manager needs; scope: *:write)
PUT    /api/v1/availability                   bulk: [{room_type, from, to, weekdays, allotment, closed, cta, ctd, min_stay}]
PUT    /api/v1/rates                          bulk: [{room_type, rate_plan, from, to, weekdays, price}]

# Webhook management
GET|POST|DELETE /api/v1/webhooks
POST   /api/v1/webhooks/{id}/test
```

`PUT` on the two ARI endpoints is deliberate: they are **idempotent range writes**, not increments. A channel manager that retries a failed push must be able to send the identical body again with no side effect. Both accept a weekday mask so "Saturdays in July, min-stay 3" is one call rather than thirty-one.

### Booking safely over HTTP

1. **`Idempotency-Key` is required on `POST /bookings`.** The key, a hash of the request body, and the eventual response go into `idempotency_keys`. A replay with the same key and same body returns the stored response verbatim; the same key with a *different* body is a `409`. Network timeouts on booking creation are routine and this is the only thing standing between one guest and two bookings.
2. **A hold is not a booking.** `POST /bookings` returns a `pending` booking with `hold_expires_at` in the payload, so the partner knows exactly how long they have to pay or confirm.
3. **Availability responses carry `ETag` / `Last-Modified`.** A polling channel manager gets `304` most of the time instead of hammering the availability table every minute.
4. **Rate limits per client**, returning `429` with `Retry-After` and `X-RateLimit-Remaining`. Reads and writes get separate buckets — a partner's aggressive polling must not lock them out of confirming a booking.

### Outbound webhooks

Push, so partners don't have to poll. Events: `booking.created`, `booking.updated`, `booking.cancelled`, `payment.succeeded`, `payment.refunded`, `availability.changed`, `rates.changed`.

- Signed `X-Signature: t=<ts>,v1=<hmac-sha256 of "t.body">` with the endpoint's own secret. The timestamp is inside the signed payload so a replayed delivery can be rejected
- Delivery is a queued job with exponential backoff (1m, 5m, 30m, 2h, 6h, 24h); every attempt lands in `webhook_deliveries`
- `consecutive_failures > 20` auto-disables the endpoint and mails the client's contact
- **The receiver must treat webhooks as at-least-once and possibly out-of-order.** Every payload carries `event_id` and the resource's `updated_at`; document this loudly, because a partner that assumes exactly-once ordering will eventually resurrect a cancelled booking

### Documentation

An **OpenAPI 3.1 spec generated from the code** (Scramble or a similar attribute-driven generator), served at `/api/docs` via Scalar, with a downloadable `openapi.json`. Hand-written API docs drift within one sprint and then actively mislead. A Postman collection and a small PHP/JS client generated from the spec turn a two-week partner integration into two days — and if you're selling this platform to hotels, the integration story is part of the product.

### The multi-install consequence — worth deciding early

Because every hotel is its own install (§1), each has its own API base URL, its own keys, and its own OpenAPI document. That's clean for a single hotel and awkward for anyone wanting *all* your hotels at once — a group site, a regional portal, a comparison page.

If that's a real requirement, the answer is **not** to abandon per-hotel installs. It's a thin aggregator in front of them: a small service holding a registry of installs and their keys, exposing one `/api/v1/hotels/{slug}/…` surface and fanning out (a cross-hotel availability search is N parallel HTTP calls with a short cache). A few hundred lines, no shared database, no change to the isolation model. Design the per-hotel API well enough to be fanned out — consistent shapes, no per-hotel special cases — and this stays a weekend rather than a rewrite.

---

## 18. Development workflow

- **Local:** SQLite + `php artisan serve`, seeders generating a demo hotel with rooms, rates, and 60 days of bookings
- **Testing:** Pest. Non-negotiable coverage — the availability query (every restriction, and specifically the `check_out`-row CTD boundary), the pricing engine (every rule and their interactions), **the concurrency test** (two simultaneous attempts on the last room: exactly one wins and the loser gets `NoAvailabilityException`, *not* a driver error — this is what catches the SQLite `BEGIN IMMEDIATE` regression from §6), hold expiry, **the late-webhook test** (payment succeeds after the hold expired and the room was resold → auto-refund, never a phantom confirmation), webhook idempotency and replay, cancellation refund maths, `availability:reconcile` detecting injected drift, locale fallback and per-locale slug routing, iCal round-trip and the removal guard, **API contract tests** (every endpoint validated against the OpenAPI spec, so a response-shape change breaks CI rather than a partner), idempotency replay returning the identical response, ARI `PUT` proving idempotent on repeat, and an installer test that runs the full wizard headlessly on both database engines and then asserts a second install attempt 404s
- **CI:** GitHub Actions running the suite against **both** SQLite and MySQL on every push — this is the only thing that keeps the "portable" promise honest. Plus Pint (style), PHPStan level 6, and the asset build; the built artifact is what deploys.
- **Staging:** one install at `demo.<yourdomain>` on the same pipeline, doubling as the sales demo for prospective hotels.

---

## 19. Build order

| Phase | Scope | Estimate |
|---|---|---|
| **1. Foundation** | Laravel skeleton, config/theme layer, DB schema + migrations, Filament auth and roles, seeders, `new-hotel.sh`, CI on both engines | 2–3 weeks |
| **2. Booking core** | Availability service, rate engine, search (incl. multi-room composition), public room pages, checkout, holds + locking + reconciliation, confirmation mails, admin booking management, **the bespoke availability grid** (custom Livewire — Filament gives you nothing here; this alone is ~1 week) | 5–6 weeks |
| **3. Payments & content** | Stripe (deposit + SCA-safe balance + refunds + late-webhook handling), invoices with per-VAT-rate lines, CMS pages, menus, galleries, extras, promo codes, enquiries, full i18n across public site and mails | 4–5 weeks |
| **4. First hotel live** | Theme, real content and translations, DNS + TLS, SEO, analytics, staff training, soft launch | 1–2 weeks |
| **5. Channels & scale** | iCal two-way sync (§9), reports, pre-arrival/post-stay automation, second and third hotel onboarding, Ansible multi-install deploy | 3–4 weeks |
| **6. Public API** (§17) | `/api/v1` read endpoints, API clients + scopes + sandbox, `POST /bookings` with idempotency, ARI `PUT` endpoints, outbound webhooks, OpenAPI spec + `/api/docs`, admin screens for keys and deliveries | 3–4 weeks |
| **7. Optional** | Channel-manager aggregator integration, physical room assignment + housekeeping, reviews, loyalty/repeat-guest discounts, PMS export, multi-hotel aggregator service | as needed |

**Where the two new pieces go:**

- **The installation wizard (§16) belongs in phase 1, not later.** It is how every subsequent hotel gets set up, so building it after five manual installs means five hotels configured in ways the wizard doesn't reproduce — exactly the divergence the per-install model can't survive. Budget **+1.5–2 weeks in phase 1** (steps 0–6 and the guard logic), then **+3–4 days in phase 3** to add the rooms/content/theme steps once those subsystems exist. Build the non-interactive `doba:install` twin at the same time; the wizard is a front-end onto it.
- **The public API (§17) belongs after phase 5, not before.** The domain services it exposes must be settled first — an API published over a still-changing booking engine means either a frozen bad design or a breaking change in front of partners. The one exception: define the **JSON shapes and the OpenAPI skeleton** during phase 2, because doing so forces the domain services into a clean, HTTP-shaped contract while it's still free to change them.

**Roughly 4–4.5 months to a live first hotel** (phases 1–4, including the wizard), **6–7 months to a repeatable multi-hotel product with a documented public API.** One experienced full-stack Laravel developer. Phase 2 is where the real difficulty is; phases 1 and 3 are mostly well-trodden ground. The estimates assume the correctness work in §6 and §8 is done properly rather than deferred — deferring it doesn't save time, it moves the cost to the first overbooking.

---

## 20. Risks and open questions

**Risks**

1. **SQLite under concurrency.** Correct *only* with the `BEGIN IMMEDIATE` connection override in §6 — without it you get sporadic `SQLITE_BUSY` failures at exactly the wrong moment. Even done right it is serialised: fine for a small pension, wrong above roughly one booking per second or with several staff in the admin panel at once. Make the migration path (`php artisan doba:migrate-to-mysql`) part of phase 5 so it isn't an emergency later.
2. **Install sprawl.** The per-hotel model only stays cheap if #15's automation exists and no hotel ever gets a code fork. Enforce this from hotel #2.
3. **iCal sync gap.** Real overbooking risk across OTAs. Set expectations in writing with each hotelier, and keep enough allotment buffer or move to a channel manager when OTA volume is significant.
4. **Timezone and date handling.** Store dates as plain `date` (a night is a night, not an instant); store timestamps UTC; render in `DOBA_TIMEZONE`. Most booking bugs are date bugs.
5. **Translation debt.** Four locales means every content change is four edits. The completeness indicator in the admin panel is what keeps this from silently rotting.
6. **The installer as an attack surface.** A wizard reachable on an installed site is a full takeover. The double marker, the file token and the 404-once-installed rule (§16) are all load-bearing, and the CI test that a second install attempt fails is what keeps them that way through future refactors.
7. **API version lock-in.** Once one partner integrates, `/api/v1` shapes are frozen for as long as that partner exists. Generating the OpenAPI spec from code and running contract tests (§18) makes accidental breakage impossible; deliberate change means `/api/v2` alongside `/api/v1`. Publish the API later rather than sooner, and only when the domain services underneath have stopped moving.
8. **A second front door to the booking engine.** The API can oversell rooms in ways the website never would — but only if it grows its own logic. The single rule in §17 (API controllers are thin layers over the same services) is the whole defence, and it needs to be enforced in review, because the shortcut is always tempting under deadline.

**Open questions to settle before phase 1**

- Who owns the hotel's domain and DNS — you or the hotelier? (Affects the onboarding script and TLS automation.)
- One server hosting many installs, or one small VPS per hotel? (Cost vs blast radius; the plan supports both, many-on-one is cheaper and assumed above.)
- Is the commercial model a one-off build per hotel, or a monthly SaaS fee? A SaaS model eventually pushes toward a shared control plane for licensing and billing — worth knowing now, even if you don't build it yet.
- Are the target properties plain hotels, or spa/sanatorium properties selling **treatment packages** (multi-night stay + board + medical procedures)? Packages are a genuinely different sellable unit — a `package` rate-plan type with bundled extras and a required minimum stay. If that's the market, model it in phase 1 rather than retrofitting it.
- Does any hotel need multiple properties under one admin login? The plan says no (`multi_property = false`); reversing that later is expensive.

**Open questions on the installer and API specifically**

- **Who runs the installer?** If it's always you over SSH, the wizard is a convenience and phase 1 can ship a thin version. If hoteliers or resellers self-install on shared hosting, it is a primary product surface and deserves the full treatment above — including translations of the wizard itself.
- **Who is the API actually for?** The three plausible answers lead to different first endpoints: a **channel manager** needs the ARI `PUT` endpoints and `GET /bookings?updated_since` first; a **travel agency or group portal** needs search, quotes and `POST /bookings`; the **hotel's own mobile app** needs Sanctum and read endpoints, and is much less work. If you know which comes first, phase 6 shrinks by roughly a third.
- **Do you need a cross-hotel view** (one portal listing every hotel you host)? If yes, the aggregator at the end of §17 should be sketched during phase 6 so the per-hotel API is designed to be fanned out. If no, skip it entirely.
- **Is the API a paid product feature?** If partners pay for access, `api_clients` needs plan limits and usage metering from day one; retrofitting billing onto an already-integrated API is unpleasant.

<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Doba — the per-hotel switchboard
|--------------------------------------------------------------------------
|
| This file is byte-identical across every install. Everything that varies
| per hotel lives in .env, in the database (settings + content), or in an
| optional theme directory. Anything a hotelier should be able to change
| themselves belongs in the database and is edited in the admin panel —
| .env and this file hold only what a developer sets at install time.
|
*/

return [

    'theme' => env('DOBA_THEME', 'default'),

    'currency' => env('DOBA_CURRENCY', 'EUR'),

    'timezone' => env('DOBA_TIMEZONE', 'Europe/Berlin'),

    /*
    |--------------------------------------------------------------------------
    | Locales
    |--------------------------------------------------------------------------
    |
    | The first entry is the default locale. When 'hide_default_prefix' is true
    | the default locale is served without a URL prefix (/rooms instead of
    | /en/rooms) and the prefixed variant permanently redirects to it, so a
    | page never exists at two URLs.
    |
    */

    'locales' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('DOBA_LOCALES', 'en,de,fr,nl'))
    ))),

    'hide_default_prefix' => (bool) env('DOBA_HIDE_DEFAULT_PREFIX', false),

    'checkin_from' => env('DOBA_CHECKIN_FROM', '15:00'),

    'checkout_until' => env('DOBA_CHECKOUT_UNTIL', '11:00'),

    'features' => [
        'online_payment' => (bool) env('FEATURE_PAYMENT', true),
        'deposit_only' => (bool) env('FEATURE_DEPOSIT_ONLY', true),
        'ota_sync' => (bool) env('FEATURE_OTA_SYNC', false),
        'promo_codes' => (bool) env('FEATURE_PROMO', true),
        'extras' => (bool) env('FEATURE_EXTRAS', true),
        'reviews' => (bool) env('FEATURE_REVIEWS', false),
        'multi_property' => false, // always false in this model
    ],

    /*
    |--------------------------------------------------------------------------
    | Payments
    |--------------------------------------------------------------------------
    |
    | The default gateway the checkout initiates with; provider credentials
    | live in config/services.php. Webhook endpoints stay active for every
    | configured provider regardless of this default, because switching
    | gateways must not orphan events for payments made under the old one.
    |
    */

    'payment' => [
        'gateway' => env('PAYMENT_GATEWAY', 'manual'), // stripe | paypal | liqpay | coinbase | manual
    ],

    /*
    |--------------------------------------------------------------------------
    | Seeded admin account
    |--------------------------------------------------------------------------
    |
    | Used only by the database seeder for the demo /admin login. Override
    | both before seeding any install reachable from the internet; the
    | installation wizard (§16) replaces this with a real owner-account step.
    |
    */

    'admin' => [
        'email' => env('DOBA_ADMIN_EMAIL', 'admin@example.com'),
        'password' => env('DOBA_ADMIN_PASSWORD', 'password'),
    ],

    'booking' => [
        'hold_minutes' => (int) env('DOBA_HOLD_MINUTES', 20),
        'max_nights' => (int) env('DOBA_MAX_NIGHTS', 30),
        'booking_window_days' => (int) env('DOBA_BOOKING_WINDOW_DAYS', 540),
        'cancellation_policy' => env('DOBA_CANCELLATION_POLICY', 'flexible'),
    ],

    /*
    |--------------------------------------------------------------------------
    | SEO
    |--------------------------------------------------------------------------
    |
    | Half the point of a direct-booking site: the hotel is paying this
    | platform to stop paying an OTA 15–18% commission, which only works if
    | the site ranks and converts. These are the knobs; the implementation
    | lives in App\Support\Seo.
    |
    */

    'seo' => [
        // Emit <meta name="robots" content="noindex"> across the whole site.
        // Staging installs set DOBA_NOINDEX=true so demo.<domain> never ranks.
        'noindex' => (bool) env('DOBA_NOINDEX', false),

        // Appended to every <title> that does not opt out.
        'title_separator' => env('DOBA_TITLE_SEPARATOR', ' · '),

        // Truncation budgets — Google renders roughly this much.
        'title_max' => 60,
        'description_max' => 160,

        // Widths generated for responsive images (srcset), in CSS pixels.
        'image_widths' => [480, 768, 1024, 1440, 1920],

        // Fallback social sharing image, relative to the public disk.
        'og_image' => env('DOBA_OG_IMAGE'),

        'twitter_site' => env('DOBA_TWITTER_SITE'),

        // Written into robots.txt and the sitemap index.
        'sitemap_path' => 'sitemap.xml',
    ],

    /*
    |--------------------------------------------------------------------------
    | Structured data
    |--------------------------------------------------------------------------
    |
    | schema.org type for the property. Hotel is right for most; the plan's
    | §20 open question about spa/sanatorium properties is what would change
    | this to Resort or MedicalClinic.
    |
    */

    'schema' => [
        'type' => env('DOBA_SCHEMA_TYPE', 'Hotel'),
        'price_range' => env('DOBA_PRICE_RANGE', '€€'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security headers
    |--------------------------------------------------------------------------
    |
    | HSTS is on wherever HTTPS is real, which is right for a hotel serving
    | its own domain. Turn it off where the install does NOT own the domain
    | it answers on — a preview or demo running under someone else's
    | wildcard host, where `includeSubDomains` would assert a year of
    | policy over a domain that is not yours to set it for.
    |
    */

    'security' => [
        'hsts' => (bool) env('DOBA_HSTS', true),
    ],

];

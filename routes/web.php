<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminAvailabilityController;
use App\Http\Controllers\Admin\AdminChannelController;
use App\Http\Controllers\Admin\AdminEventController;
use App\Http\Controllers\Admin\AdminExtraController;
use App\Http\Controllers\Admin\AdminInvoiceController;
use App\Http\Controllers\Admin\AdminPageController;
use App\Http\Controllers\Admin\AdminPromoCodeController;
use App\Http\Controllers\Admin\AdminRatePlanController;
use App\Http\Controllers\Admin\AdminVenueController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\PhotoController;
use App\Http\Controllers\Admin\StyleController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\IcalController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\RoomTypeController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\VenueController;
use App\Http\Middleware\SetLocale;
use App\Support\Routing\Localization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public site
|--------------------------------------------------------------------------
|
| Routes are registered once per locale, with the locale as both the URL
| prefix and the route-name prefix ("de.rooms.show"). The *path segment* is
| translated too — /de/zimmer, /en/rooms — because a German URL containing
| the English word for the thing it sells is a URL that ranks for nothing.
|
| Registration order is load-bearing. The CMS catch-all is a single-segment
| {slug}, so if the prefix-less default locale were registered first it
| would match "/de" before the German group ever saw it. Prefixed groups
| first, prefix-less default last.
|
*/

Route::get('sitemap.xml', SitemapController::class)->name('sitemap');
Route::get('robots.txt', [SitemapController::class, 'robots'])->name('robots');

// Never localised and never in the sitemap: a machine subscribes to it,
// and the token in the path is what stands in for a login (§9).
Route::get('ical/{roomType}/{token}.ics', [IcalController::class, 'show'])
    ->whereNumber('roomType')
    ->name('ical');

/*
| Provider webhooks (§8). CSRF-exempt (see bootstrap/app.php) because the
| caller is Stripe/PayPal/LiqPay/Coinbase, not a browser — each request
| authenticates by its own signature scheme instead, verified in the
| gateway before anything is acted on.
*/
Route::post('webhooks/stripe', [PaymentWebhookController::class, 'stripe'])->name('webhooks.stripe');
Route::post('webhooks/paypal', [PaymentWebhookController::class, 'paypal'])->name('webhooks.paypal');
Route::post('webhooks/liqpay', [PaymentWebhookController::class, 'liqpay'])->name('webhooks.liqpay');
Route::post('webhooks/coinbase', [PaymentWebhookController::class, 'coinbase'])->name('webhooks.coinbase');

/*
| The pre-Filament admin area. Locale-less on purpose: staff URLs are not
| content, and 'admin' is a RESERVED segment no CMS slug can claim.
*/
Route::prefix('admin')->group(function (): void {
    Route::middleware('guest')->group(function (): void {
        Route::get('login', [AuthController::class, 'showLogin'])->name('admin.login');
        Route::post('login', [AuthController::class, 'login'])
            ->middleware('throttle:10,1')
            ->name('admin.login.submit');
    });

    Route::middleware('auth')->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout'])->name('admin.logout');
        Route::redirect('/', '/admin/pages');

        Route::get('pages', [AdminPageController::class, 'index'])->name('admin.pages');
        Route::get('pages/create', [AdminPageController::class, 'create'])->name('admin.pages.create');
        Route::post('pages', [AdminPageController::class, 'store'])->name('admin.pages.store');
        Route::get('pages/{page}/edit', [AdminPageController::class, 'edit'])->name('admin.pages.edit');
        Route::put('pages/{page}', [AdminPageController::class, 'update'])->name('admin.pages.update');
        Route::delete('pages/{page}', [AdminPageController::class, 'destroy'])->name('admin.pages.destroy');

        Route::get('events', [AdminEventController::class, 'index'])->name('admin.events');
        Route::get('events/create', [AdminEventController::class, 'create'])->name('admin.events.create');
        Route::post('events', [AdminEventController::class, 'store'])->name('admin.events.store');
        Route::get('events/{event}/edit', [AdminEventController::class, 'edit'])->name('admin.events.edit');
        Route::put('events/{event}', [AdminEventController::class, 'update'])->name('admin.events.update');
        Route::delete('events/{event}', [AdminEventController::class, 'destroy'])->name('admin.events.destroy');

        Route::get('styles', [StyleController::class, 'edit'])->name('admin.styles');
        Route::put('styles', [StyleController::class, 'update'])->name('admin.styles.update');

        Route::get('availability', [AdminAvailabilityController::class, 'index'])->name('admin.availability');
        Route::put('availability', [AdminAvailabilityController::class, 'update'])->name('admin.availability.update');

        Route::get('venues', [AdminVenueController::class, 'index'])->name('admin.venues');
        Route::get('venues/create', [AdminVenueController::class, 'create'])->name('admin.venues.create');
        Route::post('venues', [AdminVenueController::class, 'store'])->name('admin.venues.store');
        Route::get('venues/{venue}/edit', [AdminVenueController::class, 'edit'])->name('admin.venues.edit');
        Route::put('venues/{venue}', [AdminVenueController::class, 'update'])->name('admin.venues.update');
        Route::delete('venues/{venue}', [AdminVenueController::class, 'destroy'])->name('admin.venues.destroy');
        Route::post('venues/{venue}/sections', [AdminVenueController::class, 'storeSection'])->name('admin.venues.sections.store');
        Route::delete('venues/{venue}/sections/{section}', [AdminVenueController::class, 'destroySection'])->name('admin.venues.sections.destroy');
        Route::post('venues/{venue}/dishes', [AdminVenueController::class, 'saveDishes'])->name('admin.venues.dishes');

        Route::get('promo-codes', [AdminPromoCodeController::class, 'index'])->name('admin.promo-codes');
        Route::get('promo-codes/create', [AdminPromoCodeController::class, 'create'])->name('admin.promo-codes.create');
        Route::post('promo-codes', [AdminPromoCodeController::class, 'store'])->name('admin.promo-codes.store');
        Route::get('promo-codes/{promoCode}/edit', [AdminPromoCodeController::class, 'edit'])->name('admin.promo-codes.edit');
        Route::put('promo-codes/{promoCode}', [AdminPromoCodeController::class, 'update'])->name('admin.promo-codes.update');
        Route::delete('promo-codes/{promoCode}', [AdminPromoCodeController::class, 'destroy'])->name('admin.promo-codes.destroy');

        Route::get('channels', [AdminChannelController::class, 'index'])->name('admin.channels');
        Route::get('channels/create', [AdminChannelController::class, 'create'])->name('admin.channels.create');
        Route::post('channels', [AdminChannelController::class, 'store'])->name('admin.channels.store');
        Route::get('channels/{feed}/edit', [AdminChannelController::class, 'edit'])->name('admin.channels.edit');
        Route::put('channels/{feed}', [AdminChannelController::class, 'update'])->name('admin.channels.update');
        Route::delete('channels/{feed}', [AdminChannelController::class, 'destroy'])->name('admin.channels.destroy');
        Route::post('channels/{feed}/sync', [AdminChannelController::class, 'sync'])->name('admin.channels.sync');
        Route::post('channels/review/{channelBooking}', [AdminChannelController::class, 'resolve'])->name('admin.channels.resolve');

        Route::get('invoices', [AdminInvoiceController::class, 'index'])->name('admin.invoices');
        Route::get('invoices/{invoice}.pdf', [AdminInvoiceController::class, 'download'])->name('admin.invoices.download');

        Route::get('rate-plans', [AdminRatePlanController::class, 'index'])->name('admin.rate-plans');
        Route::get('rate-plans/create', [AdminRatePlanController::class, 'create'])->name('admin.rate-plans.create');
        Route::post('rate-plans', [AdminRatePlanController::class, 'store'])->name('admin.rate-plans.store');
        Route::get('rate-plans/{ratePlan}/edit', [AdminRatePlanController::class, 'edit'])->name('admin.rate-plans.edit');
        Route::put('rate-plans/{ratePlan}', [AdminRatePlanController::class, 'update'])->name('admin.rate-plans.update');
        Route::delete('rate-plans/{ratePlan}', [AdminRatePlanController::class, 'destroy'])->name('admin.rate-plans.destroy');

        Route::get('extras', [AdminExtraController::class, 'index'])->name('admin.extras');
        Route::get('extras/create', [AdminExtraController::class, 'create'])->name('admin.extras.create');
        Route::post('extras', [AdminExtraController::class, 'store'])->name('admin.extras.store');
        Route::get('extras/{extra}/edit', [AdminExtraController::class, 'edit'])->name('admin.extras.edit');
        Route::put('extras/{extra}', [AdminExtraController::class, 'update'])->name('admin.extras.update');
        Route::delete('extras/{extra}', [AdminExtraController::class, 'destroy'])->name('admin.extras.destroy');

        Route::get('photos', [PhotoController::class, 'index'])->name('admin.photos');
        Route::get('photos/{subject}', [PhotoController::class, 'show'])->name('admin.photos.show');
        Route::post('photos/{subject}', [PhotoController::class, 'store'])->name('admin.photos.store');
        Route::put('photos/{subject}/{media}', [PhotoController::class, 'update'])->name('admin.photos.update');
        Route::delete('photos/{subject}/{media}', [PhotoController::class, 'destroy'])->name('admin.photos.destroy');
    });
});

/*
| The bare domain, when every locale is prefixed. Content-negotiated and a
| 302, never a 301: the target depends on Accept-Language, and a permanent
| redirect would be cached by the first visitor's browser and then served to
| that visitor in that language forever.
*/
if (Localization::prefix(Localization::defaultLocale()) !== '') {
    Route::get('/', static function (Request $request) {
        $locale = $request->getPreferredLanguage(Localization::locales());

        return redirect(
            Localization::route('home', [], Localization::isSupported($locale) ? $locale : null),
            302
        );
    })->name('root');
}

$locales = Localization::locales();

usort($locales, static fn (string $a, string $b): int => (Localization::prefix($a) === '' ? 1 : 0)
    <=> (Localization::prefix($b) === '' ? 1 : 0));

foreach ($locales as $locale) {
    Route::prefix(Localization::prefix($locale))
        ->name($locale.'.')
        ->middleware(SetLocale::class.':'.$locale)
        ->group(function () use ($locale): void {
            Route::get('/', HomeController::class)->name('home');

            $rooms = Localization::segment('rooms', $locale);

            Route::get($rooms, [RoomTypeController::class, 'index'])->name('rooms.index');
            Route::get($rooms.'/{slug}', [RoomTypeController::class, 'show'])->name('rooms.show');

            $dining = Localization::segment('dining', $locale);

            Route::get($dining, [VenueController::class, 'index'])->name('venues.index');
            Route::get($dining.'/{slug}', [VenueController::class, 'show'])->name('venues.show');

            $events = Localization::segment('events', $locale);

            Route::get($events, [EventController::class, 'index'])->name('events.index');
            Route::get($events.'/{slug}', [EventController::class, 'show'])->name('events.show');

            $booking = Localization::segment('booking', $locale);

            Route::get($booking.'/search', [BookingController::class, 'search'])->name('booking.search');
            Route::get($booking.'/checkout', [BookingController::class, 'checkout'])->name('booking.checkout');
            Route::post($booking, [BookingController::class, 'store'])
                ->middleware('throttle:booking')
                ->name('booking.store');
            Route::get($booking.'/pay/{reference}', [BookingController::class, 'pay'])->name('booking.pay');
            Route::get($booking.'/confirmation/{reference}', [BookingController::class, 'confirmation'])->name('booking.confirmation');
            Route::get($booking.'/manage/{reference}/{token}', [BookingController::class, 'manage'])->name('booking.manage');
            Route::get($booking.'/manage/{reference}/{token}/invoice.pdf', [BookingController::class, 'invoice'])
                ->name('booking.invoice');
            Route::post($booking.'/manage/{reference}/{token}/cancel', [BookingController::class, 'cancel'])
                ->middleware('throttle:booking')
                ->name('booking.cancel');

            $contact = Localization::segment('contact', $locale);

            Route::get($contact, [ContactController::class, 'show'])->name('contact');
            Route::post($contact, [ContactController::class, 'submit'])
                ->middleware('throttle:contact')
                ->name('contact.submit');

            // CMS pages last: {slug} would otherwise swallow "rooms".
            Route::get('{slug}', [PageController::class, 'show'])
                ->where('slug', '(?!'.implode('|', Localization::RESERVED).')[a-z0-9\-]+')
                ->name('page');
        });
}

/*
| With DOBA_HIDE_DEFAULT_PREFIX on, the default locale is served without a
| prefix — so /en/rooms and /rooms would both exist. Permanently redirect
| the prefixed form rather than serving it: duplicate content is exactly
| what the canonical tags elsewhere are there to prevent.
*/
if (Localization::prefix($default = Localization::defaultLocale()) === '') {
    Route::get($default.'/{path?}', static fn (?string $path = null) => redirect(
        '/'.ltrim((string) $path, '/'), 301
    ))->where('path', '.*');
}

<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminEventController;
use App\Http\Controllers\Admin\AdminPageController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\StyleController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\RoomTypeController;
use App\Http\Controllers\SitemapController;
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

            $events = Localization::segment('events', $locale);

            Route::get($events, [EventController::class, 'index'])->name('events.index');
            Route::get($events.'/{slug}', [EventController::class, 'show'])->name('events.show');

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

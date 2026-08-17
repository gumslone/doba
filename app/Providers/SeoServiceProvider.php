<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Hotel\HotelSettings;
use App\Support\Mail\MailSettings;
use App\Support\Maintenance\Backups;
use App\Support\Seo\Seo;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Throwable;

class SeoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped, not singleton: settings belong to a request, and an
        // instance that outlives one serves yesterday's hotel name.
        $this->app->scoped(HotelSettings::class);

        // The backup directory is configuration, not something the
        // container can guess from a type hint.
        $this->app->bind(Backups::class, static fn (): Backups => Backups::make());

        // One SEO bag per request: a controller fills it in, the layout
        // renders it. Scoped rather than singleton so a queued job or an
        // Octane worker never leaks one request's meta into the next.
        $this->app->scoped(Seo::class, static fn ($app) => new Seo(
            $app->make(HotelSettings::class)->name
        ));
    }

    public function boot(): void
    {
        // Emptied as each request picks up its route. `scoped` alone is a
        // promise the container keeps only where a request ends the
        // process; this makes it true everywhere, so one page's schema can
        // never be published on the next.
        Event::listen(RouteMatched::class, function (): void {
            $this->app->make(Seo::class)->reset();
            $this->app->make(HotelSettings::class)->refresh();
        });

        // The hotel's own mail configuration, pushed into the live config
        // before anything sends. Applied on the queue too (MessageSending
        // fires there as well) — a confirmation that goes out from a
        // worker must use the same server the hotelier tested, not
        // whatever .env happened to say when the worker booted.
        Event::listen(MessageSending::class, function (): void {
            try {
                $this->app->make(MailSettings::class)->apply();
            } catch (Throwable) {
                // Before the first migration there is no settings table.
                // Failing to read it must not stop the installer sending
                // its own test message.
            }
        });

        View::composer('*', function ($view): void {
            $view->with([
                'hotel' => $this->app->make(HotelSettings::class),
                'seo' => $this->app->make(Seo::class),
            ]);
        });

        // @jsonld($array) — a JSON-LD block escaped for embedding in HTML.
        // JSON_HEX_TAG is the part that matters: without it a room description
        // containing "</script>" ends the block and injects markup.
        Blade::directive('jsonld', static fn (string $expression): string => "<?php
            \$__jsonld = {$expression};
            if (\$__jsonld) {
                echo '<script type=\"application/ld+json\">',
                     json_encode(\$__jsonld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
                     '</script>';
            }
            unset(\$__jsonld);
        ?>");
    }
}

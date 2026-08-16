<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Hotel\HotelSettings;
use App\Support\Seo\Seo;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class SeoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HotelSettings::class);

        // One SEO bag per request: a controller fills it in, the layout
        // renders it. Scoped rather than singleton so a queued job or an
        // Octane worker never leaks one request's meta into the next.
        $this->app->scoped(Seo::class, static fn ($app) => new Seo(
            $app->make(HotelSettings::class)->name
        ));
    }

    public function boot(): void
    {
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

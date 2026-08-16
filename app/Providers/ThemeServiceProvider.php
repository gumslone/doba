<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Theme resolution (§3).
 *
 * Prepends resources/views/themes/{DOBA_THEME} to the view finder, so any
 * Blade file a hotel wants to change is copied into its theme folder and
 * edited there while everything else falls through to `default`.
 *
 * Colours, fonts, logo and hero images are *settings*, not theme files —
 * only structural layout changes justify a theme override. A theme that
 * exists to change a hex code is a code fork wearing a hat, and code forks
 * are what kill the per-install model (§1).
 */
class ThemeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $theme = (string) config('doba.theme', 'default');

        if ($theme !== 'default' && is_dir($path = resource_path("views/themes/{$theme}"))) {
            $this->app['view']->prependLocation($path);
        }

        $this->app['view']->prependLocation(resource_path('views/themes/default'));
    }
}

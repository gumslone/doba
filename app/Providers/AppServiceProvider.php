<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Public-form rate limits (§14). Keyed by IP: the form is
        // pre-authentication by definition. Five a minute is generous for a
        // human retrying a typo and useless for a spam run.
        RateLimiter::for('contact', static fn (Request $request) => Limit::perMinute(5)->by((string) $request->ip()));
    }
}

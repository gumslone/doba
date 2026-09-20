<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Alerts\Alerts;
use App\Support\Demo\Demo;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // A queued job that fails for the last time was a confirmation
        // mail, a webhook delivery or an invoice PDF nobody will now get.
        // On a shared host nobody reads failed_jobs; the hotelier reads
        // their inbox (§15). Throttled per job class, one message an hour.
        Queue::failing(function (JobFailed $event): void {
            $name = $event->job->resolveName();

            app(Alerts::class)->send('A background job keeps failing: '.class_basename($name), [
                'Job: '.$name,
                'Error: '.mb_substr($event->exception->getMessage(), 0, 500),
                'It has used all its retries and will not run again by itself.',
            ]);
        });

        // HTTPS enforced (§14): behind Apache/mod_proxy_fcgi the app, not
        // the vhost, is what generates canonical URLs, hreflang hrefs and
        // redirect targets — one http:// among them undoes the whole
        // canonical story.
        //
        // Only where the hotel has SAID its address is https. Forcing it on
        // an install whose APP_URL is http:// — a first look on localhost,
        // a container on a LAN, a NAS — redirects every page to a port
        // that speaks no TLS, and the person trying Doba for the first
        // time sees a browser error instead of the wizard. The health
        // page nags about plain http in production instead.
        // A public demo never mails a stranger's address and never takes
        // a card, whatever the .env underneath it says (§22).
        if (Demo::enabled()) {
            config([
                'mail.default' => 'log',
                'doba.features.online_payment' => false,
                'doba.features.reviews' => true,
                'doba.seo.noindex' => true,
            ]);
        }

        if (self::shouldForceHttps((string) $this->app->environment(), (string) config('app.url'))) {
            URL::forceScheme('https');
        }

        // Public-form rate limits (§14). Keyed by IP: the form is
        // pre-authentication by definition. Five a minute is generous for a
        // human retrying a typo and useless for a spam run.
        RateLimiter::for('contact', static fn (Request $request) => Limit::perMinute(5)->by((string) $request->ip()));

        // Booking creation takes real inventory, so it is limited harder
        // than a form post: ten a minute is far more than a human books
        // and far less than a script needs to sweep the calendar.
        RateLimiter::for('booking', static fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));
    }

    /**
     * Production, and an address the hotel itself declared as https.
     */
    public static function shouldForceHttps(string $environment, string $appUrl): bool
    {
        return $environment === 'production' && str_starts_with($appUrl, 'https://');
    }
}

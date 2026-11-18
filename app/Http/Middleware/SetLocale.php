<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Routing\Localization;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locale resolution, in the order from §10:
 *
 *     route locale → session → Accept-Language → APP_LOCALE
 *
 * The route locale comes from the middleware parameter rather than from
 * sniffing the URL, because the prefix-less default locale has no segment
 * to sniff.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next, ?string $locale = null): Response
    {
        $locale = $this->resolve($request, $locale);

        app()->setLocale($locale);
        $request->session()->put('locale', $locale);

        // Dates, times and currency are formatted with intl per locale (§10),
        // and Carbon needs telling separately from the app locale.
        //
        // The locale only. This used to switch PHP's default timezone to
        // the hotel's as well — for guest pages alone, so cron, the admin
        // and webhooks read and wrote the same columns in another zone.
        // The whole process runs on the hotel's clock now (config/app.php).
        Carbon::setLocale($locale);

        $response = $next($request);

        // Vary on Accept-Language so a shared cache cannot serve a German
        // page to an English visitor on a URL that has no locale prefix.
        if (Localization::prefix($locale) === '') {
            $response->headers->set('Vary', 'Accept-Language', false);
        }

        $response->headers->set('Content-Language', Localization::bcp47($locale));

        return $response;
    }

    protected function resolve(Request $request, ?string $routeLocale): string
    {
        if (Localization::isSupported($routeLocale)) {
            return $routeLocale;
        }

        $session = $request->session()->get('locale');

        if (Localization::isSupported(is_string($session) ? $session : null)) {
            return $session;
        }

        $preferred = $request->getPreferredLanguage(Localization::locales());

        if (Localization::isSupported($preferred)) {
            return $preferred;
        }

        return Localization::defaultLocale();
    }
}

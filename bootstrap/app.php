<?php

declare(strict_types=1);

use App\Http\Middleware\SetLocale;
use App\Models\Redirect;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // SetLocale is applied per locale route group with the locale as a
        // parameter (see routes/web.php), not globally: routes outside a
        // group — sitemap.xml, robots.txt, /up, webhooks — have no locale to
        // negotiate and address every locale explicitly instead.
        //
        // Behind Apache with mod_proxy_fcgi the app must trust the proxy, or
        // every canonical URL, redirect and hreflang href it generates comes
        // out as http:// on an HTTPS-only site.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Legacy-URL redirects, checked only on a 404.
         *
         * Doing this in middleware would mean a redirects query on every
         * request forever, to serve a table that is empty on most installs
         * and only ever consulted for URLs from a site that no longer
         * exists. A 404 is exactly the moment the question becomes relevant.
         */
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! Schema::hasTable('redirects')) {
                return null;
            }

            $from = Redirect::normalise($request->path());

            $redirect = Redirect::query()
                ->where('from', $from)
                ->where('is_active', true)
                ->first();

            if ($redirect === null) {
                return null;
            }

            $redirect->increment('hits');

            $target = str_starts_with($redirect->to, 'http')
                ? $redirect->to
                : url($redirect->to);

            // Carry the query string across: an old campaign URL keeps its
            // UTM parameters, so the migration does not blank out the
            // attribution the hotel is paying to measure.
            if (($query = $request->getQueryString()) !== null) {
                $target .= (str_contains($target, '?') ? '&' : '?').$query;
            }

            return redirect()->away($target, $redirect->code);
        });
    })->create();

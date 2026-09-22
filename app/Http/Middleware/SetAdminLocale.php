<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Routing\AdminLocale;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff URLs carry no language — /admin/front-desk is not content — so
 * the language comes from the person, not the path.
 */
class SetAdminLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        // The login screen's language links: remembered for the session,
        // so the choice survives the redirect into the panel.
        if (is_string($picked = $request->query('lang')) && array_key_exists($picked, AdminLocale::available()) && $request->hasSession()) {
            $request->session()->put(AdminLocale::SESSION_KEY, $picked);
        }

        $locale = AdminLocale::resolve($request);

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }
}

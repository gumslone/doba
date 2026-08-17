<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The §14 response-header set, applied to every route.
 *
 * The CSP is deliberately modest rather than absent: 'self' everywhere,
 * with inline styles and scripts allowed because the pages legitimately
 * carry inline JSON-LD blocks and the settings-driven branding <style>.
 * What it still ends outright: loading script/styles/frames from any
 * third-party origin, plugin content, and being framed by another site.
 * No external origin appears in it because the platform genuinely uses
 * none — self-hosted assets are a §11 performance rule first.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data:",
                "font-src 'self'",
                "connect-src 'self'",
                // The one third-party exception: the click-to-load map
                // iframe on the contact page. Nothing loads from Google
                // until the visitor presses the button; the CSP merely
                // permits the frame once they do.
                'frame-src https://maps.google.com https://www.google.com',
                "frame-ancestors 'self'",
                "base-uri 'self'",
                "form-action 'self' https://www.liqpay.ua",
                "object-src 'none'",
            ]));
        }

        // HSTS only where HTTPS is real: sent over local HTTP it would be
        // ignored, but sent from a misconfigured proxy it could lock a
        // domain out of plain HTTP for a year.
        if ($request->secure() && config('doba.security.hsts', true)) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}

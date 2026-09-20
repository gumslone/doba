<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Demo\Demo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the dangerous half of the admin read-only on a public demo.
 *
 * Refused with a sentence, not a 403: the person clicking is evaluating
 * the software, and "this is switched off in the demo" is an answer,
 * where an error page is a reason to close the tab.
 */
class DemoGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Demo::allows($request)) {
            return $next($request);
        }

        return redirect()->back(fallback: '/admin/front-desk')->with('demo_blocked', __('admin.demo_blocked'));
    }
}

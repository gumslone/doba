<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Install\Installer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Send an uninstalled copy to the wizard, and hide the wizard once it is
 * installed (§16).
 *
 * The second half is a 404 rather than a redirect or an "already
 * installed" page: a scanner walking the internet for /install should
 * learn nothing from the answer, least of all that this is a Doba site
 * with an installer in it.
 */
class EnsureInstalled
{
    public function __construct(protected Installer $installer) {}

    public function handle(Request $request, Closure $next): Response
    {
        $wantsInstaller = $request->is('install', 'install/*');

        if ($this->installer->isInstalled()) {
            if ($wantsInstaller) {
                throw new NotFoundHttpException;
            }

            return $next($request);
        }

        // Not installed. The wizard itself, and the handful of routes a
        // browser fetches alongside it, must still answer.
        if ($wantsInstaller || $this->isAllowedWhileInstalling($request)) {
            return $next($request);
        }

        return redirect('/install');
    }

    protected function isAllowedWhileInstalling(Request $request): bool
    {
        // /up is the health check a load balancer polls, and an install
        // that reports itself down for its whole duration gets restarted
        // half way through by the thing watching it.
        return $request->is('up', 'build/*', 'favicon.ico');
    }
}

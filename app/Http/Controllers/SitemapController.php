<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Routing\Localization;
use App\Support\Seo\SitemapGenerator;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class SitemapController extends Controller
{
    /**
     * Serves the nightly artifact written by `doba:sitemap` when it exists,
     * and generates on the fly when it does not.
     *
     * Both paths exist on purpose: the file is what a healthy install serves
     * (zero database work for a crawler hitting it hourly), and the live
     * fallback is what keeps a fresh install, a shared-hosting box with no
     * cron, and the first hour after launch from returning a 404 to Search
     * Console.
     */
    public function __invoke(SitemapGenerator $generator): Response
    {
        $path = public_path((string) config('doba.seo.sitemap_path', 'sitemap.xml'));

        $xml = is_file($path)
            ? (string) file_get_contents($path)
            : Cache::remember('doba:sitemap', now()->addHour(), static fn (): string => $generator->generate());

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }

    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
        ];

        if (config('doba.seo.noindex')) {
            // A staging install must not be crawlable. This is the same flag
            // that emits <meta name="robots" content="noindex">, so the two
            // can never disagree — which is how a demo site ends up ranking
            // above the hotel it is demonstrating.
            $lines[] = 'Disallow: /';
        } else {
            $lines[] = 'Disallow: /admin';
            $lines[] = 'Disallow: /install';
            $lines[] = 'Disallow: /api/';
            // The booking funnel past the search results is per-session,
            // parameterised and worthless in an index — and crawling it
            // manufactures holds against real inventory.
            foreach (Localization::locales() as $locale) {
                $prefix = Localization::prefix($locale);
                $prefix = $prefix === '' ? '' : '/'.$prefix;
                $lines[] = 'Disallow: '.$prefix.'/'.Localization::segment('booking', $locale).'/';
            }
            $lines[] = '';
            $lines[] = 'Sitemap: '.url((string) config('doba.seo.sitemap_path', 'sitemap.xml'));
        }

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}

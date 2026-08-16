<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Seo\SitemapGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class GenerateSitemapCommand extends Command
{
    protected $signature = 'doba:sitemap
                            {--path= : Write somewhere other than public/sitemap.xml}';

    protected $description = 'Regenerate the XML sitemap with per-locale hreflang alternates';

    public function handle(SitemapGenerator $generator): int
    {
        $path = $this->option('path')
            ?: public_path((string) config('doba.seo.sitemap_path', 'sitemap.xml'));

        $xml = $generator->generate();

        // Write-then-rename: a crawler hitting the file mid-write must never
        // get half a document, which an XML parser rejects outright rather
        // than partially accepting.
        $temporary = $path.'.'.getmypid().'.tmp';

        if (file_put_contents($temporary, $xml) === false || ! rename($temporary, $path)) {
            @unlink($temporary);
            $this->error("Could not write {$path}.");

            return self::FAILURE;
        }

        Cache::forget('doba:sitemap');

        $this->info(sprintf('Wrote %d URLs to %s', $generator->count(), $path));

        return self::SUCCESS;
    }
}

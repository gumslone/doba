<?php

declare(strict_types=1);

namespace App\Support\Seo;

use App\Models\Page;
use App\Models\RoomType;
use App\Support\Routing\Localization;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * XML sitemap with per-URL hreflang alternates.
 *
 * The alternate block is the part people skip and the part that matters for
 * a four-language hotel site: it is how Google learns that /de/zimmer/x and
 * /en/rooms/x are the same room in two languages rather than two competing
 * pages. Every URL in a language group lists *every* member of the group,
 * including itself — that reciprocity is required, and a one-way alternate
 * is silently ignored.
 *
 * Only translated URLs are listed. A room with no Dutch translation has no
 * Dutch URL, so it does not appear in a Dutch <url> entry and does not get
 * a nl alternate anywhere.
 */
final class SitemapGenerator
{
    /**
     * @var array<int,array{loc:string,lastmod:?string,changefreq:string,priority:string,alternates:array<string,string>}>
     */
    protected array $urls = [];

    public function generate(): string
    {
        $this->urls = [];

        $this->addStaticRoutes();
        $this->addRoomTypes();
        $this->addPages();

        return $this->render();
    }

    protected function addStaticRoutes(): void
    {
        $home = Localization::alternates('home');

        foreach ($home as $locale => $url) {
            $this->push($url, null, 'weekly', '1.0', $home);
        }

        if (Localization::hasRoute('rooms.index', Localization::defaultLocale())) {
            $rooms = Localization::alternates('rooms.index');

            foreach ($rooms as $url) {
                $this->push($url, null, 'weekly', '0.9', $rooms);
            }
        }
    }

    protected function addRoomTypes(): void
    {
        RoomType::query()
            ->active()
            ->ordered()
            ->with('translations')
            ->chunk(200, function (Collection $roomTypes): void {
                foreach ($roomTypes as $roomType) {
                    $alternates = Localization::alternates(
                        'rooms.show',
                        static fn (string $l): ?array => ($s = $roomType->slug($l)) ? ['slug' => $s] : null
                    );

                    foreach ($alternates as $url) {
                        $this->push($url, $roomType->updated_at, 'weekly', '0.8', $alternates);
                    }
                }
            });
    }

    protected function addPages(): void
    {
        Page::query()
            ->published()
            ->where('noindex', false)
            ->with('translations')
            ->chunk(200, function (Collection $pages): void {
                foreach ($pages as $page) {
                    $alternates = Localization::alternates(
                        'page',
                        static fn (string $l): ?array => ($s = $page->slug($l)) ? ['slug' => $s] : null
                    );

                    foreach ($alternates as $url) {
                        $this->push($url, $page->updated_at, 'monthly', '0.6', $alternates);
                    }
                }
            });
    }

    /**
     * @param  array<string,string>  $alternates
     */
    protected function push(string $loc, ?CarbonInterface $lastmod, string $changefreq, string $priority, array $alternates = []): void
    {
        $this->urls[] = [
            'loc' => $loc,
            'lastmod' => $lastmod?->toAtomString(),
            'changefreq' => $changefreq,
            'priority' => $priority,
            'alternates' => $alternates,
        ];
    }

    protected function render(): string
    {
        $xml = new \XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');

        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xml->writeAttribute('xmlns:xhtml', 'http://www.w3.org/1999/xhtml');

        foreach ($this->urls as $url) {
            $xml->startElement('url');
            $xml->writeElement('loc', $url['loc']);

            if ($url['lastmod'] !== null) {
                $xml->writeElement('lastmod', $url['lastmod']);
            }

            $xml->writeElement('changefreq', $url['changefreq']);
            $xml->writeElement('priority', $url['priority']);

            foreach ($url['alternates'] as $locale => $href) {
                $xml->startElement('xhtml:link');
                $xml->writeAttribute('rel', 'alternate');
                $xml->writeAttribute('hreflang', Localization::bcp47($locale));
                $xml->writeAttribute('href', $href);
                $xml->endElement();
            }

            if ($url['alternates'] !== []
                && ($xDefault = Localization::xDefault($url['alternates'])) !== null) {
                $xml->startElement('xhtml:link');
                $xml->writeAttribute('rel', 'alternate');
                $xml->writeAttribute('hreflang', 'x-default');
                $xml->writeAttribute('href', $xDefault);
                $xml->endElement();
            }

            $xml->endElement(); // url
        }

        $xml->endElement(); // urlset
        $xml->endDocument();

        return $xml->outputMemory();
    }

    public function count(): int
    {
        return count($this->urls);
    }
}

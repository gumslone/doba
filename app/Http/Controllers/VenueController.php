<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Venue;
use App\Support\Hotel\HotelSettings;
use App\Support\Routing\Localization;
use App\Support\Seo\JsonLd;
use App\Support\Seo\Seo;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class VenueController extends Controller
{
    public function index(Seo $seo, HotelSettings $hotel): View
    {
        $venues = Venue::query()
            ->active()
            ->with(['translations', 'media'])
            ->orderBy('sort_order')
            ->get()
            // A venue with no translation in this language has no URL here,
            // so listing it would link to a 404 (§10).
            ->filter(static fn (Venue $venue): bool => $venue->slug() !== null)
            ->values();

        $seo->title(__('menu.title'))
            ->description(__('menu.meta_description', ['hotel' => $hotel->name]))
            ->canonical(Localization::route('venues.index'))
            ->alternates(Localization::alternates('venues.index'))
            ->breadcrumb($hotel->name, Localization::route('home'))
            ->breadcrumb(__('menu.title'), Localization::route('venues.index'));

        return view('venues.index', ['venues' => $venues]);
    }

    public function show(string $slug, Seo $seo, HotelSettings $hotel): View
    {
        $locale = app()->getLocale();

        $venue = Venue::findBySlug($slug, $locale)
            ?? throw new NotFoundHttpException("No venue [{$slug}] in [{$locale}].");

        $url = Localization::route('venues.show', ['slug' => $slug]);
        $cover = $venue->coverImage();

        $seo->title($venue->t('meta_title') ?: $venue->t('name'))
            ->description($venue->t('meta_description') ?: $venue->t('tagline'))
            ->image($cover?->url() ?? $hotel->ogImage())
            ->canonical($url)
            ->alternates(Localization::alternates(
                'venues.show',
                static fn (string $l): ?array => ($s = $venue->slug($l)) ? ['slug' => $s] : null
            ))
            ->breadcrumb($hotel->name, Localization::route('home'))
            ->breadcrumb(__('menu.title'), Localization::route('venues.index'))
            ->breadcrumb((string) $venue->t('name'), $url)
            // The menu itself is published as structured data: this is the
            // one page where search engines will show a hotel's food.
            ->schema(JsonLd::venue($venue, $hotel, [
                'url' => $url,
                'image' => $cover?->url(),
            ]));

        return view('venues.show', [
            'venue' => $venue,
            'sections' => $venue->sections
                ->where('is_active', true)
                ->filter(static fn ($section): bool => $section->dishes->where('is_available', true)->isNotEmpty()),
        ]);
    }
}

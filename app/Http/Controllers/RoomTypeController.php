<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Media;
use App\Models\RoomType;
use App\Support\Hotel\HotelSettings;
use App\Support\Routing\Localization;
use App\Support\Seo\JsonLd;
use App\Support\Seo\Seo;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RoomTypeController extends Controller
{
    public function index(Seo $seo, HotelSettings $hotel): View
    {
        $roomTypes = RoomType::query()
            ->active()
            ->ordered()
            ->with(['translation', 'translations', 'media'])
            ->get();

        $seo->title(__('seo.rooms.title', ['hotel' => $hotel->name]))
            ->description(__('seo.rooms.description', ['hotel' => $hotel->name]))
            ->image($hotel->ogImage())
            ->canonical(Localization::route('rooms.index'))
            ->alternates(Localization::alternates('rooms.index'))
            ->breadcrumb($hotel->name, Localization::route('home'))
            ->breadcrumb(__('common.rooms'), Localization::route('rooms.index'))
            ->schema($this->itemList($roomTypes));

        return view('rooms.index', [
            'roomTypes' => $roomTypes,
        ]);
    }

    public function show(string $slug, Seo $seo, HotelSettings $hotel): View
    {
        $locale = app()->getLocale();

        $roomType = RoomType::findBySlug($slug, $locale)
            ?? throw new NotFoundHttpException("No room type [{$slug}] in [{$locale}].");

        $url = Localization::route('rooms.show', ['slug' => $slug]);

        $seo->title($roomType->t('meta_title') ?: $roomType->t('name'))
            ->description($roomType->t('meta_description') ?: $roomType->t('short_description'))
            ->image($roomType->coverImage()?->url() ?? $hotel->ogImage())
            ->type('product')
            ->canonical($url)
            // A room translated into two of four languages gets exactly two
            // alternates. Pointing the other two at a fallback-rendered page
            // tells Google those URLs are translations when they are not.
            ->alternates(Localization::alternates(
                'rooms.show',
                static fn (string $l): ?array => ($s = $roomType->slug($l)) ? ['slug' => $s] : null
            ))
            ->breadcrumb($hotel->name, Localization::route('home'))
            ->breadcrumb(__('common.rooms'), Localization::route('rooms.index'))
            ->breadcrumb((string) $roomType->t('name'), $url)
            ->schema(JsonLd::room($roomType, [
                'locale' => $locale,
                'url' => $url,
                'images' => $roomType->media->map(static fn (Media $image): string => $image->url())->all(),
            ]));

        return view('rooms.show', [
            'roomType' => $roomType,
            // Other categories a guest might take instead — the cheapest
            // alternative is often the booking that would otherwise be lost.
            'similar' => RoomType::query()
                ->active()
                ->ordered()
                ->whereKeyNot($roomType->getKey())
                ->with(['translation', 'translations', 'media'])
                ->limit(3)
                ->get()
                ->filter(static fn (RoomType $other): bool => $other->slug() !== null)
                ->values(),
        ]);
    }

    /**
     * @param  Collection<int,RoomType>  $roomTypes
     * @return array<string,mixed>
     */
    protected function itemList($roomTypes): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'itemListElement' => $roomTypes
                ->values()
                ->filter(static fn (RoomType $rt): bool => $rt->slug() !== null)
                ->map(static fn (RoomType $rt, int $i): array => [
                    '@type' => 'ListItem',
                    'position' => $i + 1,
                    'name' => $rt->t('name'),
                    'url' => Localization::route('rooms.show', ['slug' => $rt->slug()]),
                ])
                ->all(),
        ];
    }
}

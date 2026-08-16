<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Faq;
use App\Models\Gallery;
use App\Models\RoomType;
use App\Support\Hotel\HotelSettings;
use App\Support\Routing\Localization;
use App\Support\Seo\JsonLd;
use App\Support\Seo\Seo;
use Illuminate\Contracts\View\View;

class HomeController extends Controller
{
    /**
     * The four-up strip under the booking bar. Stored as a translatable
     * setting; an install that has not set them simply gets no strip
     * rather than four lorem-ipsum claims.
     *
     * @return array<int,array{icon:string,title:string,subtitle:string}>
     */
    protected function usps(HotelSettings $hotel): array
    {
        $usps = $hotel->get('general.usps');

        if (! is_array($usps)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($usp): ?array => is_array($usp) && ($usp['title'] ?? '') !== ''
            ? [
                'icon' => (string) ($usp['icon'] ?? 'check'),
                'title' => (string) $usp['title'],
                'subtitle' => (string) ($usp['subtitle'] ?? ''),
            ]
            : null, $usps)));
    }

    /**
     * House-wide facilities for the "included in your rate" grid.
     *
     * @return array<int,string>
     */
    protected function houseAmenities(HotelSettings $hotel): array
    {
        $list = $hotel->get('amenities.list');

        return is_array($list)
            ? array_values(array_filter(array_map('strval', $list)))
            : [];
    }

    public function __invoke(Seo $seo, HotelSettings $hotel): View
    {
        $roomTypes = RoomType::query()
            ->active()
            ->ordered()
            ->with(['translation', 'translations', 'media'])
            ->limit(3)
            ->get();

        $faqs = Faq::forCurrentLocale();

        $gallery = Gallery::hotel();
        $hero = $gallery->coverImage();

        $events = Event::query()
            ->published()
            ->upcoming()
            ->with(['translation', 'translations'])
            ->limit(3)
            ->get()
            ->filter(static fn (Event $event): bool => $event->slug() !== null)
            ->values();

        // The house photos double as the Hotel node's image set and as the
        // og:image fallback — a hotel with photos should never share as a
        // blank card.
        $hotelSchema = JsonLd::hotel($hotel);

        if ($gallery->media->isNotEmpty()) {
            $hotelSchema['image'] = $gallery->media->map(static fn ($photo): string => $photo->url())->all();
        }

        $seo->title($hotel->get('seo.title') ?: $hotel->name)
            ->description($hotel->get('seo.description'))
            ->image($hotel->ogImage() ?? $hero?->url())
            ->canonical(Localization::route('home'))
            ->alternates(Localization::alternates('home'))
            ->breadcrumb($hotel->name, Localization::route('home'))
            ->schema($hotelSchema)
            ->schema(JsonLd::website(
                $hotel,
                Localization::hasRoute('booking.search') ? Localization::route('booking.search') : null
            ));

        // The markup only ever describes questions that are visibly on the
        // page — structured data for invisible content is a spam signal.
        if (($faqSchema = JsonLd::faqs($faqs)) !== null) {
            $seo->schema($faqSchema);
        }

        return view('home', [
            'roomTypes' => $roomTypes,
            'faqs' => $faqs,
            'events' => $events,
            'hero' => $hero,
            'galleryPhotos' => $gallery->media->reject(static fn ($photo): bool => $photo->is($hero))->take(6)->values(),
            // Editable in the settings table so a hotelier can say what
            // makes their house worth choosing, rather than inheriting
            // whatever four claims the theme shipped with.
            'usps' => $this->usps($hotel),
            'amenities' => $this->houseAmenities($hotel),
        ]);
    }
}

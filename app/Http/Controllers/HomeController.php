<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Faq;
use App\Models\RoomType;
use App\Support\Hotel\HotelSettings;
use App\Support\Routing\Localization;
use App\Support\Seo\JsonLd;
use App\Support\Seo\Seo;
use Illuminate\Contracts\View\View;

class HomeController extends Controller
{
    public function __invoke(Seo $seo, HotelSettings $hotel): View
    {
        $roomTypes = RoomType::query()
            ->active()
            ->ordered()
            ->with(['translation', 'translations', 'media'])
            ->limit(3)
            ->get();

        $faqs = Faq::forCurrentLocale();

        $events = Event::query()
            ->published()
            ->upcoming()
            ->with(['translation', 'translations'])
            ->limit(3)
            ->get()
            ->filter(static fn (Event $event): bool => $event->slug() !== null)
            ->values();

        $seo->title($hotel->get('seo.title') ?: $hotel->name)
            ->description($hotel->get('seo.description'))
            ->image($hotel->ogImage())
            ->canonical(Localization::route('home'))
            ->alternates(Localization::alternates('home'))
            ->breadcrumb($hotel->name, Localization::route('home'))
            ->schema(JsonLd::hotel($hotel))
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
        ]);
    }
}

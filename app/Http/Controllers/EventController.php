<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Event;
use App\Support\Hotel\HotelSettings;
use App\Support\Routing\Localization;
use App\Support\Seo\JsonLd;
use App\Support\Seo\Seo;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class EventController extends Controller
{
    public function index(Seo $seo, HotelSettings $hotel): View
    {
        $events = Event::query()
            ->published()
            ->upcoming()
            ->with(['translation', 'translations', 'media'])
            ->get()
            ->filter(static fn (Event $event): bool => $event->slug() !== null)
            ->values();

        $seo->title(__('events.title'))
            ->description(__('events.meta_description', ['hotel' => $hotel->name]))
            ->canonical(Localization::route('events.index'))
            ->alternates(Localization::alternates('events.index'))
            ->breadcrumb($hotel->name, Localization::route('home'))
            ->breadcrumb(__('events.title'), Localization::route('events.index'));

        return view('events.index', ['events' => $events]);
    }

    public function show(string $slug, Seo $seo, HotelSettings $hotel): View
    {
        $locale = app()->getLocale();

        $event = Event::findBySlug($slug, $locale)
            ?? throw new NotFoundHttpException("No event [{$slug}] in [{$locale}].");

        $url = Localization::route('events.show', ['slug' => $slug]);
        $cover = $event->media->first();

        $seo->title($event->t('meta_title') ?: $event->t('title'))
            ->description($event->t('meta_description') ?: $event->t('excerpt'))
            ->image($cover?->url() ?? $hotel->ogImage())
            ->type('article')
            ->canonical($url)
            ->alternates(Localization::alternates(
                'events.show',
                static fn (string $l): ?array => ($s = $event->slug($l)) ? ['slug' => $s] : null
            ))
            ->breadcrumb($hotel->name, Localization::route('home'))
            ->breadcrumb(__('events.title'), Localization::route('events.index'))
            ->breadcrumb((string) $event->t('title'), $url)
            ->schema(JsonLd::event($event, $hotel, [
                'url' => $url,
                'image' => $cover?->url(),
            ]));

        return view('events.show', ['event' => $event]);
    }
}

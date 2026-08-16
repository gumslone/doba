@php
    use App\Models\Page;
    use App\Support\Routing\Localization;

    // Pages flagged show_in_menu join the fixed room/events/contact links,
    // so a hotelier can add "Spa" or "Restaurant" to the nav from the
    // admin without a theme change.
    //
    // Legal pages are excluded by code: they are legally required to be
    // reachable, which the footer already guarantees, and putting the
    // imprint between "Rooms" and "Contact" spends the most valuable
    // navigation on the least valuable page.
    $menuPages = Page::query()
        ->published()
        ->where('show_in_menu', true)
        ->whereNotIn('code', ['imprint', 'privacy', 'terms', 'cancellation'])
        ->with('translation')
        ->orderBy('sort_order')
        ->get()
        ->filter(fn (Page $page): bool => $page->slug() !== null);
@endphp

<header class="site"><div class="wrap">
    <a class="brand" href="{{ Localization::route('home') }}">
        @if ($logo = $hotel->logo())
            <img src="{{ $logo }}" alt="{{ $hotel->name }}" class="brand-mark" width="34" height="34">
        @else
            {{-- A neutral mark until the hotel uploads a logo: a peak and a
                 sun, tinted with the install's own brand colours. --}}
            <svg class="brand-mark" viewBox="0 0 40 40" fill="none" aria-hidden="true">
                <rect width="40" height="40" rx="2" fill="var(--doba-primary)"/>
                <path d="M8 27l7.5-12 4.6 7.4L23.6 17 32 27H8z" fill="var(--doba-accent)"/>
                <circle cx="27.5" cy="12.5" r="3" fill="#e8c877"/>
            </svg>
        @endif
        <span class="brand-name">
            {{ $hotel->name }}
            @if ($city = $hotel->get('contact.city'))
                <span class="brand-sub">{{ $city }}</span>
            @endif
        </span>
    </a>

    <nav class="main" aria-label="Main">
        <a href="{{ Localization::route('rooms.index') }}">{{ __('common.rooms') }}</a>
        <a href="{{ Localization::route('events.index') }}">{{ __('events.title') }}</a>
        @foreach ($menuPages as $page)
            <a href="{{ Localization::route('page', ['slug' => $page->slug()]) }}">{{ $page->t('title') }}</a>
        @endforeach
        <a href="{{ Localization::route('contact') }}">{{ __('contact.title') }}</a>
    </nav>

    <a class="btn btn--gold" href="{{ Localization::route('booking.search') }}">{{ __('booking.book_now') }}</a>
</div></header>

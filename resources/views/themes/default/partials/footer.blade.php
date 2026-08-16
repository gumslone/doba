@php
    use App\Models\Page;
    use App\Support\Routing\Localization;

    // Legally required pages in DE/PL/UA (§16 step 8). Rendering them from
    // the pages table rather than hard-coding keeps the footer correct when
    // a hotelier renames "Impressum" or adds a house-rules page.
    $footerPages = Page::query()
        ->published()
        ->where('show_in_menu', true)
        ->with('translation')
        ->orderBy('sort_order')
        ->get()
        ->filter(fn (Page $page): bool => $page->slug() !== null);
@endphp

<footer class="mt-16 border-t border-neutral-200 bg-neutral-50">
    <div class="mx-auto grid max-w-6xl gap-8 px-4 py-10 text-sm text-neutral-600 sm:grid-cols-3">
        <div>
            <p class="font-semibold text-neutral-900">{{ $hotel->name }}</p>
            @if ($street = $hotel->get('contact.street'))
                <p class="mt-2">{{ $street }}</p>
                <p>{{ $hotel->get('contact.postal_code') }} {{ $hotel->get('contact.city') }}</p>
            @endif
            @if ($phone = $hotel->get('contact.phone'))
                <p class="mt-2"><a href="tel:{{ preg_replace('/[^+0-9]/', '', $phone) }}" class="hover:underline">{{ $phone }}</a></p>
            @endif
            @if ($email = $hotel->get('contact.email'))
                <p><a href="mailto:{{ $email }}" class="hover:underline">{{ $email }}</a></p>
            @endif
        </div>

        <div>
            <p class="font-semibold text-neutral-900">{{ __('common.rooms') }}</p>
            <p class="mt-2">{{ __('common.check_in_from', ['time' => config('doba.checkin_from')]) }}</p>
            <p>{{ __('common.check_out_until', ['time' => config('doba.checkout_until')]) }}</p>
            <p class="mt-2">{{ __('seo.direct_booking_note') }}</p>
        </div>

        @if ($footerPages->isNotEmpty())
            <nav aria-label="Footer">
                <ul class="space-y-1">
                    @foreach ($footerPages as $page)
                        <li>
                            <a href="{{ Localization::route('page', ['slug' => $page->slug()]) }}" class="hover:underline">
                                {{ $page->t('title') }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>
        @endif
    </div>
</footer>

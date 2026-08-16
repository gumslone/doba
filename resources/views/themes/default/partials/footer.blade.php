@php
    use App\Models\Page;
    use App\Support\Hotel\Maps;
    use App\Support\Routing\Localization;

    $footerPages = Page::query()
        ->published()
        ->with('translation')
        ->orderBy('sort_order')
        ->get()
        ->filter(fn (Page $page): bool => $page->slug() !== null);

    // Legally required pages in DE/PL/UA (§16 step 8) go in their own
    // column; everything else is "explore".
    $legalCodes = ['imprint', 'privacy', 'terms', 'cancellation'];
    $legal = $footerPages->whereIn('code', $legalCodes);
    $explore = $footerPages->whereNotIn('code', $legalCodes);
@endphp

<footer class="site">
    <div class="wrap">
        <div class="fcols">
            <div>
                <h5>{{ $hotel->name }}</h5>
                @if ($tagline = $hotel->get('general.tagline'))
                    <p style="max-width:36ch;color:#9fb0a5">{{ $tagline }}</p>
                @endif
                @if ($street = $hotel->get('contact.street'))
                    <p style="margin-top:14px">
                        {{ $street }}<br>
                        {{ $hotel->get('contact.postal_code') }} {{ $hotel->get('contact.city') }}
                        @if ($country = $hotel->get('contact.country'))<br>{{ $country }}@endif
                    </p>
                @endif
                @if ($mapLink = Maps::link($hotel))
                    <p style="margin-top:10px">
                        <a href="{{ $mapLink }}" target="_blank" rel="noopener">{{ __('contact.map_open') }} ↗</a>
                    </p>
                @endif
            </div>

            <div>
                <h5>{{ __('common.explore') }}</h5>
                <ul>
                    <li><a href="{{ Localization::route('rooms.index') }}">{{ __('common.rooms') }}</a></li>
                    <li><a href="{{ Localization::route('events.index') }}">{{ __('events.title') }}</a></li>
                    @foreach ($explore as $page)
                        <li><a href="{{ Localization::route('page', ['slug' => $page->slug()]) }}">{{ $page->t('title') }}</a></li>
                    @endforeach
                    <li><a href="{{ Localization::route('contact') }}">{{ __('contact.title') }}</a></li>
                </ul>
            </div>

            @if ($legal->isNotEmpty())
                <div>
                    <h5>{{ __('common.legal') }}</h5>
                    <ul>
                        @foreach ($legal as $page)
                            <li><a href="{{ Localization::route('page', ['slug' => $page->slug()]) }}">{{ $page->t('title') }}</a></li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div>
                <h5>{{ __('contact.title') }}</h5>
                <ul>
                    @if ($phone = $hotel->get('contact.phone'))
                        <li><a href="tel:{{ preg_replace('/[^+0-9]/', '', $phone) }}">{{ $phone }}</a></li>
                    @endif
                    @if ($email = $hotel->get('contact.email'))
                        <li><a href="mailto:{{ $email }}">{{ $email }}</a></li>
                    @endif
                </ul>
                <p style="margin-top:16px;color:#9fb0a5">
                    {{ __('common.check_in_from', ['time' => config('doba.checkin_from')]) }}<br>
                    {{ __('common.check_out_until', ['time' => config('doba.checkout_until')]) }}
                </p>
            </div>
        </div>

        <div class="fbottom">
            <span>
                © {{ date('Y') }} {{ $hotel->name }} ·
                {{ __('common.powered_by') }}
                <a href="https://github.com/gumslone/doba" rel="noopener"><strong style="color:#cbd8ce">Doba</strong></a>
            </span>
        </div>
    </div>
</footer>

@php
    use App\Support\Routing\Localization;

    // The language switcher points at the current page in each language,
    // which the SEO bag already resolved (§10). On pages with no
    // alternates the strip is simply the contact details.
    $alternates = $seo->getAlternates();
@endphp

<div class="topbar"><div class="wrap">
    <div class="topbar-info">
        @if ($phone = $hotel->get('contact.phone'))
            <a href="tel:{{ preg_replace('/[^+0-9]/', '', $phone) }}">{{ $phone }}</a>
        @endif
        @if ($email = $hotel->get('contact.email'))
            <a href="mailto:{{ $email }}">{{ $email }}</a>
        @endif
        @if ($street = $hotel->get('contact.street'))
            <span>{{ $street }} · {{ $hotel->get('contact.postal_code') }} {{ $hotel->get('contact.city') }}</span>
        @endif
    </div>

    @if (count($alternates) > 1)
        <nav class="langs" aria-label="{{ __('common.language') }}">
            @foreach ($alternates as $locale => $url)
                <a class="lang" href="{{ $url }}"
                   hreflang="{{ Localization::bcp47($locale) }}"
                   lang="{{ Localization::bcp47($locale) }}"
                   @if ($locale === app()->getLocale()) aria-current="true" @endif>{{ $locale }}</a>
            @endforeach
        </nav>
    @endif
</div></div>

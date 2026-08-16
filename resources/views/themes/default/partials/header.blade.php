@php
    use App\Support\Routing\Localization;

    // The switcher points at *this page* in each language, which is only
    // possible because the SEO bag already resolved the per-locale slugs.
    // Sending every language switch to the home page is the single most
    // common way a multilingual site loses the visitor it just translated
    // for — and it wastes the hreflang set the page already emits.
    $alternates = $seo->getAlternates();
@endphp

<header class="border-b border-neutral-200">
    <div class="mx-auto flex max-w-6xl items-center justify-between gap-6 px-4 py-4">
        <a href="{{ Localization::route('home') }}" class="flex items-center gap-3 font-semibold tracking-tight">
            @if ($logo = $hotel->logo())
                <img src="{{ $logo }}" alt="{{ $hotel->name }}" class="h-8 w-auto" width="120" height="32">
            @else
                {{ $hotel->name }}
            @endif
        </a>

        <nav class="flex items-center gap-6 text-sm" aria-label="Main">
            <a href="{{ Localization::route('rooms.index') }}" class="hover:underline">{{ __('common.rooms') }}</a>

            @if (count($alternates) > 1)
                <ul class="flex items-center gap-2" aria-label="{{ __('common.language') }}">
                    @foreach ($alternates as $locale => $url)
                        <li>
                            <a href="{{ $url }}"
                               hreflang="{{ Localization::bcp47($locale) }}"
                               lang="{{ Localization::bcp47($locale) }}"
                               @class(['uppercase', 'font-semibold underline' => $locale === app()->getLocale()])
                               @if ($locale === app()->getLocale()) aria-current="true" @endif>
                                {{ $locale }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </nav>
    </div>
</header>

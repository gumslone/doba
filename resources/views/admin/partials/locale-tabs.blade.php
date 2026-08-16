{{--
    Per-locale editing tabs. $fieldsView names a partial rendered once per
    locale (with $locale in scope, plus whatever the parent passes). Every
    locale's fields stay in the DOM so one submit carries all languages —
    matching the "emptied title unpublishes that language" contract in the
    controllers.
--}}
@php
    use App\Support\Routing\Localization;
    $locales = Localization::locales();
@endphp

<div x-data="{ tab: '{{ $locales[0] }}' }" class="rounded border border-neutral-200 bg-white">
    <div class="flex gap-1 border-b border-neutral-200 px-2 pt-2" role="tablist">
        @foreach ($locales as $locale)
            <button type="button" role="tab"
                    @click="tab = '{{ $locale }}'"
                    :class="tab === '{{ $locale }}' ? 'border-neutral-900 font-semibold' : 'border-transparent text-neutral-500'"
                    class="rounded-t border-b-2 px-3 py-1.5 text-sm uppercase">
                {{ $locale }}
            </button>
        @endforeach
    </div>

    @foreach ($locales as $locale)
        <div x-show="tab === '{{ $locale }}'" x-cloak class="space-y-4 p-4">
            @include($fieldsView, ['locale' => $locale])
        </div>
    @endforeach
</div>

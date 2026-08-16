{{--
    Per-locale editing tabs. $fieldsView names a partial rendered once per
    locale (with $locale in scope). Every locale's fields stay in the DOM
    so one submit carries all languages — matching the "emptied title
    unpublishes that language" contract in the controllers.

    Plain DOM rather than Alpine: the §14 CSP forbids 'unsafe-eval', which
    any expression-evaluating framework needs.
--}}
@php
    use App\Support\Routing\Localization;
    $locales = Localization::locales();
@endphp

<div class="rounded border border-neutral-200 bg-white" data-locale-tabs>
    <div class="flex gap-1 border-b border-neutral-200 px-2 pt-2" role="tablist">
        @foreach ($locales as $locale)
            <button type="button" role="tab" data-locale-tab="{{ $locale }}"
                    aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                    class="rounded-t border-b-2 border-transparent px-3 py-1.5 text-sm uppercase text-neutral-500 aria-selected:border-neutral-900 aria-selected:font-semibold aria-selected:text-neutral-900">
                {{ $locale }}
            </button>
        @endforeach
    </div>

    @foreach ($locales as $locale)
        <div data-locale-panel="{{ $locale }}" @unless ($loop->first) hidden @endunless class="space-y-4 p-4">
            @include($fieldsView, ['locale' => $locale])
        </div>
    @endforeach
</div>

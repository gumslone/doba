{{-- One locale's fields for the page form. $locale and $page in scope. --}}
@php
    // fallback=false everywhere: the admin must show what THIS language
    // actually contains — a fallback-filled field would be saved back and
    // silently "translate" the page by copying English into German.
    $t = fn (string $field) => old("translations.$locale.$field", $page->exists ? $page->t($field, $locale, false) : null);
@endphp

<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label class="block text-sm font-medium">{{ __('admin.title_field') }}</label>
        <input type="text" name="translations[{{ $locale }}][title]" value="{{ $t('title') }}"
               class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
    </div>
    <div>
        <label class="block text-sm font-medium">{{ __('admin.slug') }}</label>
        <input type="text" name="translations[{{ $locale }}][slug]" value="{{ $t('slug') }}"
               placeholder="{{ __('admin.slug_hint') }}"
               class="mt-1 w-full rounded border border-neutral-300 px-3 py-2 font-mono text-sm">
        @error("translations.$locale.slug") <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
    </div>
</div>

<div>
    <label class="block text-sm font-medium">{{ __('admin.body') }}</label>
    @include('admin.partials.wysiwyg', [
        'name' => "translations[$locale][body]",
        'id' => "page-body-$locale",
        'value' => $t('body'),
    ])
</div>

<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label class="block text-sm font-medium">{{ __('admin.meta_title') }}</label>
        <input type="text" name="translations[{{ $locale }}][meta_title]" value="{{ $t('meta_title') }}"
               class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
    </div>
    <div>
        <label class="block text-sm font-medium">{{ __('admin.meta_description') }}</label>
        <input type="text" name="translations[{{ $locale }}][meta_description]" maxlength="320" value="{{ $t('meta_description') }}"
               class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
    </div>
</div>

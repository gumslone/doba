{{-- One locale's fields for the venue form. $locale and $venue in scope. --}}
@php
    $t = fn (string $field) => old("translations.$locale.$field", $venue->exists ? $venue->t($field, $locale, false) : null);
@endphp

<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label class="block text-sm font-medium">{{ __('admin.name') }}</label>
        <input type="text" name="translations[{{ $locale }}][name]" value="{{ $t('name') }}"
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
    <label class="block text-sm font-medium">{{ __('admin.tagline') }}</label>
    <input type="text" name="translations[{{ $locale }}][tagline]" value="{{ $t('tagline') }}"
           class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
</div>

<div>
    <label class="block text-sm font-medium">{{ __('admin.description') }}</label>
    <textarea name="translations[{{ $locale }}][description]" rows="4"
              class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">{{ $t('description') }}</textarea>
</div>

<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label class="block text-sm font-medium">{{ __('admin.meta_title') }}</label>
        <input type="text" name="translations[{{ $locale }}][meta_title]" value="{{ $t('meta_title') }}"
               class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
    </div>
    <div>
        <label class="block text-sm font-medium">{{ __('admin.meta_description') }}</label>
        <input type="text" name="translations[{{ $locale }}][meta_description]" value="{{ $t('meta_description') }}"
               maxlength="320" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
    </div>
</div>

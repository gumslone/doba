{{-- One locale's fields for the extra form. $locale and $extra in scope. --}}
@php
    $t = fn (string $field) => old("translations.$locale.$field", $extra->exists ? $extra->t($field, $locale, false) : null);
@endphp

<div>
    <label class="block text-sm font-medium">{{ __('admin.title_field') }}</label>
    <input type="text" name="translations[{{ $locale }}][name]" value="{{ $t('name') }}"
           class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
</div>

<div>
    <label class="block text-sm font-medium">{{ __('admin.description') }}</label>
    <textarea name="translations[{{ $locale }}][description]" rows="3" maxlength="2000"
              class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">{{ $t('description') }}</textarea>
</div>

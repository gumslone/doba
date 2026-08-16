{{-- One locale's fields for the rate plan form. $locale and $plan in scope. --}}
@php
    $t = fn (string $field) => old("translations.$locale.$field", $plan->exists ? $plan->t($field, $locale, false) : null);
@endphp

<div>
    <label class="block text-sm font-medium">{{ __('admin.title_field') }}</label>
    <input type="text" name="translations[{{ $locale }}][name]" value="{{ $t('name') }}"
           class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
</div>

<div>
    <label class="block text-sm font-medium">{{ __('admin.description') }}</label>
    <textarea name="translations[{{ $locale }}][description]" rows="2" maxlength="2000"
              class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">{{ $t('description') }}</textarea>
</div>

<div>
    <label class="block text-sm font-medium">{{ __('admin.policy_text') }}</label>
    <p class="mt-0.5 text-xs text-neutral-500">{{ __('admin.policy_text_hint') }}</p>
    <textarea name="translations[{{ $locale }}][policy_text]" rows="4" maxlength="5000"
              class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">{{ $t('policy_text') }}</textarea>
</div>

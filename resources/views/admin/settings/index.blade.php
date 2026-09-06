@extends('admin.layout', ['title' => __('admin.settings')])

@section('content')
    @php
        $v = fn (string $key, $default = '') => old(str_replace('.', '.', $key), $values[$key] ?? $default);
        $t = function (string $key, string $locale) use ($values) {
            $stored = $values[$key] ?? null;
            $current = is_array($stored) ? ($stored[$locale] ?? '') : ($locale === \App\Support\Routing\Localization::defaultLocale() ? (string) $stored : '');
            return old("translations.$key.$locale", $current);
        };
        $field = 'mt-1 w-full rounded border border-neutral-300 px-3 py-2';
        $label = 'block text-sm font-medium';
    @endphp

    <h1 class="mb-2 text-2xl font-semibold">{{ __('admin.settings') }}</h1>
    <p class="mb-6 max-w-2xl text-sm text-neutral-600">{{ __('admin.settings_intro') }}</p>

    @if (session('saved'))
        <p class="mb-6 rounded border border-green-200 bg-green-50 p-4 text-green-900">{{ session('saved') }}</p>
    @endif
    @if ($errors->any())
        <p class="mb-6 rounded border border-red-200 bg-red-50 p-4 text-red-900">{{ $errors->first() }}</p>
    @endif

    <form method="POST" action="/admin/settings" class="max-w-3xl space-y-8">
        @csrf

        <section class="rounded border border-neutral-200 bg-white p-5">
            <h2 class="font-medium">{{ __('admin.settings_general') }}</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                <div class="sm:col-span-3">
                    <label for="general_name" class="{{ $label }}">{{ __('admin.settings_name') }}</label>
                    <input id="general_name" name="general[name]" required maxlength="255" value="{{ $v('general.name') }}" class="{{ $field }}">
                </div>
                <div>
                    <label for="general_star_rating" class="{{ $label }}">{{ __('admin.settings_star_rating') }}</label>
                    <input type="number" id="general_star_rating" name="general[star_rating]" min="1" max="5" value="{{ $v('general.star_rating') }}" class="{{ $field }}">
                </div>
                <div>
                    <label for="general_since" class="{{ $label }}">{{ __('admin.settings_since') }}</label>
                    <input type="number" id="general_since" name="general[since]" min="1000" max="2100" value="{{ $v('general.since') }}" class="{{ $field }}">
                </div>
            </div>
            <div class="mt-4 grid gap-3">
                @foreach ($locales as $locale)
                    <div>
                        <label for="tagline_{{ $locale }}" class="{{ $label }}">{{ __('admin.settings_tagline') }} <span class="font-mono text-xs uppercase text-neutral-500">{{ $locale }}</span></label>
                        <input id="tagline_{{ $locale }}" name="translations[general.tagline][{{ $locale }}]" maxlength="255" value="{{ $t('general.tagline', $locale) }}" class="{{ $field }}">
                    </div>
                @endforeach
            </div>
        </section>

        <section class="rounded border border-neutral-200 bg-white p-5">
            <h2 class="font-medium">{{ __('admin.settings_contact') }}</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                @foreach (['email' => 'email', 'phone' => 'text', 'street' => 'text', 'postal_code' => 'text', 'city' => 'text', 'country' => 'text'] as $key => $type)
                    <div>
                        <label for="contact_{{ $key }}" class="{{ $label }}">{{ __('admin.settings_'.$key) }}</label>
                        <input type="{{ $type }}" id="contact_{{ $key }}" name="contact[{{ $key }}]" @required($key === 'email') value="{{ $v('contact.'.$key) }}" class="{{ $field }}">
                    </div>
                @endforeach
                <div>
                    <label for="contact_latitude" class="{{ $label }}">{{ __('admin.settings_latitude') }}</label>
                    <input id="contact_latitude" name="contact[latitude]" inputmode="decimal" value="{{ $v('contact.latitude') }}" class="{{ $field }}">
                </div>
                <div>
                    <label for="contact_longitude" class="{{ $label }}">{{ __('admin.settings_longitude') }}</label>
                    <input id="contact_longitude" name="contact[longitude]" inputmode="decimal" value="{{ $v('contact.longitude') }}" class="{{ $field }}">
                </div>
            </div>
            <p class="mt-2 text-xs text-neutral-500">{{ __('admin.settings_geo_hint') }}</p>
        </section>

        <section class="rounded border border-neutral-200 bg-white p-5">
            <h2 class="font-medium">{{ __('admin.settings_times') }}</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="env_timezone" class="{{ $label }}">{{ __('admin.settings_timezone') }}</label>
                    <select id="env_timezone" name="env[timezone]" class="{{ $field }}">
                        @foreach ($timezones as $tz)
                            <option value="{{ $tz }}" @selected(old('env.timezone', $env['timezone']) === $tz)>{{ $tz }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="env_currency" class="{{ $label }}">{{ __('admin.settings_currency') }}</label>
                    <input id="env_currency" name="env[currency]" required maxlength="3" value="{{ old('env.currency', $env['currency']) }}" class="{{ $field }} uppercase">
                </div>
                <div>
                    <label for="env_checkin_from" class="{{ $label }}">{{ __('admin.settings_checkin_from') }}</label>
                    <input type="time" id="env_checkin_from" name="env[checkin_from]" required value="{{ old('env.checkin_from', $env['checkin_from']) }}" class="{{ $field }}">
                </div>
                <div>
                    <label for="env_checkout_until" class="{{ $label }}">{{ __('admin.settings_checkout_until') }}</label>
                    <input type="time" id="env_checkout_until" name="env[checkout_until]" required value="{{ old('env.checkout_until', $env['checkout_until']) }}" class="{{ $field }}">
                </div>
            </div>
            <p class="mt-2 text-xs text-neutral-500">{{ __('admin.settings_env_hint') }}</p>
        </section>

        <section class="rounded border border-neutral-200 bg-white p-5">
            <h2 class="font-medium">{{ __('admin.settings_seo') }}</h2>
            <div class="mt-4 grid gap-3">
                @foreach ($locales as $locale)
                    <div>
                        <label for="seo_title_{{ $locale }}" class="{{ $label }}">{{ __('admin.settings_seo_title') }} <span class="font-mono text-xs uppercase text-neutral-500">{{ $locale }}</span></label>
                        <input id="seo_title_{{ $locale }}" name="translations[seo.title][{{ $locale }}]" maxlength="255" value="{{ $t('seo.title', $locale) }}" class="{{ $field }}">
                    </div>
                    <div>
                        <label for="seo_description_{{ $locale }}" class="{{ $label }}">{{ __('admin.settings_seo_description') }} <span class="font-mono text-xs uppercase text-neutral-500">{{ $locale }}</span></label>
                        <textarea id="seo_description_{{ $locale }}" name="translations[seo.description][{{ $locale }}]" rows="2" maxlength="320" class="{{ $field }}">{{ $t('seo.description', $locale) }}</textarea>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="rounded border border-neutral-200 bg-white p-5">
            <h2 class="font-medium">{{ __('admin.settings_policy') }}</h2>
            <div class="mt-4 grid gap-3">
                @foreach ($locales as $locale)
                    <div>
                        <label for="policy_{{ $locale }}" class="{{ $label }}">{{ __('admin.settings_policy_cancellation') }} <span class="font-mono text-xs uppercase text-neutral-500">{{ $locale }}</span></label>
                        <textarea id="policy_{{ $locale }}" name="translations[policy.cancellation][{{ $locale }}]" rows="2" maxlength="2000" class="{{ $field }}">{{ $t('policy.cancellation', $locale) }}</textarea>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="rounded border border-neutral-200 bg-white p-5">
            <h2 class="font-medium">{{ __('admin.settings_usps') }}</h2>
            <p class="mt-1 text-xs text-neutral-500">{{ __('admin.settings_usps_hint') }}</p>
            <div class="mt-4 space-y-2">
                @foreach (range(0, 5) as $i)
                    @php $row = old("usps.$i", $usps[$i] ?? []); @endphp
                    <div class="grid gap-2 sm:grid-cols-4">
                        <input name="usps[{{ $i }}][icon]" placeholder="{{ __('admin.settings_usp_icon') }}" maxlength="32" value="{{ $row['icon'] ?? '' }}" class="{{ $field }}">
                        <input name="usps[{{ $i }}][title]" placeholder="{{ __('admin.settings_usp_title') }}" maxlength="80" value="{{ $row['title'] ?? '' }}" class="{{ $field }}">
                        <input name="usps[{{ $i }}][subtitle]" placeholder="{{ __('admin.settings_usp_subtitle') }}" maxlength="120" value="{{ $row['subtitle'] ?? '' }}" class="{{ $field }} sm:col-span-2">
                    </div>
                @endforeach
            </div>
        </section>

        <section class="rounded border border-neutral-200 bg-white p-5">
            <h2 class="font-medium">{{ __('admin.settings_social') }}</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                @foreach (['facebook', 'instagram', 'tripadvisor'] as $key)
                    <div>
                        <label for="social_{{ $key }}" class="{{ $label }}">{{ ucfirst($key) }}</label>
                        <input type="url" id="social_{{ $key }}" name="social[{{ $key }}]" value="{{ $v('social.'.$key) }}" class="{{ $field }}">
                    </div>
                @endforeach
            </div>
        </section>

        <section class="rounded border border-neutral-200 bg-white p-5">
            <h2 class="font-medium">{{ __('admin.settings_tax') }} &amp; {{ __('admin.settings_analytics') }}</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                <div>
                    <label for="tax_vat_id" class="{{ $label }}">{{ __('admin.settings_vat_id') }}</label>
                    <input id="tax_vat_id" name="tax[vat_id]" maxlength="32" value="{{ $v('tax.vat_id') }}" class="{{ $field }}">
                </div>
                <div>
                    <label for="tax_accommodation_rate" class="{{ $label }}">{{ __('admin.settings_accommodation_rate') }}</label>
                    <input type="number" id="tax_accommodation_rate" name="tax[accommodation_rate]" min="0" max="10000" value="{{ $v('tax.accommodation_rate') }}" class="{{ $field }}">
                </div>
                <div>
                    <label for="analytics_id" class="{{ $label }}">{{ __('admin.settings_analytics_id') }}</label>
                    <input id="analytics_id" name="analytics[id]" maxlength="64" value="{{ $v('analytics.id') }}" class="{{ $field }}">
                </div>
            </div>
        </section>

        <button type="submit" class="rounded bg-neutral-900 px-5 py-2.5 text-white">{{ __('admin.save') }}</button>
    </form>
@endsection

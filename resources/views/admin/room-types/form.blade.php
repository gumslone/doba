@extends('admin.layout', ['title' => $roomType->exists ? ($roomType->t('name') ?? $roomType->code) : __('admin.room_type_new')])

@section('content')
    @php
        $field = 'mt-1 w-full rounded border border-neutral-300 px-3 py-2';
        $label = 'block text-sm font-medium';
        $tr = fn (string $locale, string $key) => old("translations.$locale.$key", $roomType->translations->firstWhere('locale', $locale)?->{$key} ?? '');
    @endphp

    <p class="mb-4 text-sm"><a href="/admin/room-types" class="text-neutral-500 hover:underline">&larr; {{ __('admin.room_types') }}</a></p>
    <h1 class="mb-6 text-2xl font-semibold">{{ $roomType->exists ? ($roomType->t('name') ?? $roomType->code) : __('admin.room_type_new') }}</h1>

    @if (session('saved'))
        <p class="mb-6 rounded border border-green-200 bg-green-50 p-4 text-green-900">{{ session('saved') }}</p>
    @endif
    @if ($errors->any())
        <p class="mb-6 rounded border border-red-200 bg-red-50 p-4 text-red-900">{{ $errors->first() }}</p>
    @endif

    <form method="POST" action="{{ $roomType->exists ? '/admin/room-types/'.$roomType->id : '/admin/room-types' }}" class="max-w-3xl space-y-8">
        @csrf
        @if ($roomType->exists) @method('PUT') @endif

        <section class="rounded border border-neutral-200 bg-white p-5">
            <label class="flex items-center gap-2 text-sm">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $roomType->is_active))>
                {{ __('admin.room_type_active') }}
            </label>

            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                @foreach (['base_occupancy' => 1, 'max_occupancy' => 1, 'max_adults' => 1, 'max_children' => 0, 'total_units' => 1, 'size_sqm' => 1] as $key => $min)
                    <div>
                        <label for="{{ $key }}" class="{{ $label }}">{{ __('admin.room_type_'.($key === 'size_sqm' ? 'size' : $key)) }}</label>
                        <input type="number" id="{{ $key }}" name="{{ $key }}" min="{{ $min }}" @required($key !== 'size_sqm') value="{{ old($key, $roomType->{$key}) }}" class="{{ $field }}">
                    </div>
                @endforeach
                <div>
                    <label for="default_rate" class="{{ $label }}">{{ __('admin.room_type_default_rate') }}</label>
                    <input type="number" id="default_rate" name="default_rate" min="0" required value="{{ old('default_rate', $roomType->default_rate) }}" class="{{ $field }}">
                </div>
                <div>
                    <label for="extra_adult_price" class="{{ $label }}">{{ __('admin.room_type_extra_adult') }}</label>
                    <input type="number" id="extra_adult_price" name="extra_adult_price" min="0" value="{{ old('extra_adult_price', $roomType->extra_adult_price) }}" class="{{ $field }}">
                </div>
                <div>
                    <label for="extra_child_price" class="{{ $label }}">{{ __('admin.room_type_extra_child') }}</label>
                    <input type="number" id="extra_child_price" name="extra_child_price" min="0" value="{{ old('extra_child_price', $roomType->extra_child_price) }}" class="{{ $field }}">
                </div>
                <div class="sm:col-span-3">
                    <label for="bed_setup" class="{{ $label }}">{{ __('admin.room_type_bed_setup') }}</label>
                    <input id="bed_setup" name="bed_setup" maxlength="120" value="{{ old('bed_setup', $roomType->bed_setup) }}" class="{{ $field }}">
                </div>
            </div>
            <p class="mt-2 text-xs text-neutral-500">{{ __('admin.room_type_units_hint') }}</p>
        </section>

        @if ($amenities->isNotEmpty())
            <section class="rounded border border-neutral-200 bg-white p-5">
                <h2 class="font-medium">{{ __('admin.room_type_amenities') }}</h2>
                <div class="mt-3 grid gap-2 sm:grid-cols-3">
                    @foreach ($amenities as $amenity)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="amenities[]" value="{{ $amenity->id }}" @checked(in_array($amenity->id, old('amenities', $selectedAmenities)))>
                            {{ $amenity->t('name') ?? $amenity->code }}
                        </label>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="rounded border border-neutral-200 bg-white p-5">
            <h2 class="font-medium">{{ __('admin.room_type_texts') }}</h2>
            @foreach ($locales as $locale)
                <fieldset class="mt-5 rounded border border-neutral-100 p-4">
                    <legend class="px-1 font-mono text-xs uppercase text-neutral-500">{{ $locale }}@if ($locale === $defaultLocale) *@endif</legend>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="{{ $label }}">{{ __('admin.room_type_name') }}</label>
                            <input name="translations[{{ $locale }}][name]" maxlength="255" @required($locale === $defaultLocale) value="{{ $tr($locale, 'name') }}" class="{{ $field }}">
                        </div>
                        <div>
                            <label class="{{ $label }}">{{ __('admin.room_type_slug') }}</label>
                            <input name="translations[{{ $locale }}][slug]" maxlength="255" value="{{ $tr($locale, 'slug') }}" class="{{ $field }} font-mono">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="{{ $label }}">{{ __('admin.room_type_short') }}</label>
                            <input name="translations[{{ $locale }}][short_description]" maxlength="500" value="{{ $tr($locale, 'short_description') }}" class="{{ $field }}">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="{{ $label }}">{{ __('admin.room_type_description') }}</label>
                            <textarea name="translations[{{ $locale }}][description]" rows="5" class="{{ $field }}">{{ $tr($locale, 'description') }}</textarea>
                        </div>
                        <div>
                            <label class="{{ $label }}">{{ __('admin.room_type_meta_title') }}</label>
                            <input name="translations[{{ $locale }}][meta_title]" maxlength="255" value="{{ $tr($locale, 'meta_title') }}" class="{{ $field }}">
                        </div>
                        <div>
                            <label class="{{ $label }}">{{ __('admin.room_type_meta_description') }}</label>
                            <input name="translations[{{ $locale }}][meta_description]" maxlength="320" value="{{ $tr($locale, 'meta_description') }}" class="{{ $field }}">
                        </div>
                    </div>
                    @if ($locale !== $defaultLocale)
                        <p class="mt-2 text-xs text-neutral-500">{{ __('admin.room_type_untranslated') }}</p>
                    @endif
                </fieldset>
            @endforeach
        </section>

        <div class="flex items-center gap-4">
            <button type="submit" class="rounded bg-neutral-900 px-5 py-2.5 text-white">{{ __('admin.save') }}</button>
            @if ($roomType->exists)
                <a href="/admin/photos/room-type:{{ $roomType->id }}" class="text-sm underline">{{ __('admin.room_type_photos') }}</a>
            @endif
        </div>
    </form>
@endsection

@extends('install.layout', [
    'title' => __('install.step_rooms'),
    'intro' => __('install.rooms_intro'),
    'step' => 'rooms',
])

@section('content')
    <form method="POST" action="/install/rooms" class="space-y-6">
        @csrf

        <fieldset>
            <legend class="text-sm font-medium">{{ __('install.template') }}</legend>
            <div class="mt-2 grid gap-2 sm:grid-cols-3">
                @foreach ($templates as $key => $rooms)
                    <label class="cursor-pointer rounded border border-neutral-200 p-3 text-sm hover:bg-neutral-50 has-[:checked]:border-neutral-900">
                        <input type="radio" name="template" value="{{ $key }}">
                        <span class="ml-1 font-medium">{{ __('install.template_'.$key) }}</span>
                        <span class="mt-1 block text-xs text-neutral-500">
                            {{ collect($rooms)->sum('units') }} {{ __('install.rooms_count') }}
                        </span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        <div>
            <p class="text-sm font-medium">{{ __('install.or_describe') }}</p>
            <div class="mt-2 space-y-2">
                @foreach (range(0, 4) as $i)
                    <div class="grid gap-2 sm:grid-cols-[2fr_1fr_1fr_1fr]">
                        <input name="rooms[{{ $i }}][name]" placeholder="{{ __('install.room_name') }}"
                               value="{{ old('rooms.'.$i.'.name') }}"
                               class="rounded border border-neutral-300 px-3 py-2 text-sm">
                        <input name="rooms[{{ $i }}][units]" type="number" min="1" placeholder="{{ __('install.room_units') }}"
                               value="{{ old('rooms.'.$i.'.units') }}"
                               class="rounded border border-neutral-300 px-3 py-2 text-sm">
                        <input name="rooms[{{ $i }}][occupancy]" type="number" min="1" placeholder="{{ __('install.room_sleeps') }}"
                               value="{{ old('rooms.'.$i.'.occupancy') }}"
                               class="rounded border border-neutral-300 px-3 py-2 text-sm">
                        <input name="rooms[{{ $i }}][price]" type="number" step="0.01" min="0" placeholder="{{ __('install.room_price') }}"
                               value="{{ old('rooms.'.$i.'.price') }}"
                               class="rounded border border-neutral-300 px-3 py-2 text-sm">
                    </div>
                @endforeach
            </div>
            <p class="mt-2 text-xs text-neutral-500">{{ __('install.rooms_hint') }}</p>
        </div>

        <button type="submit" class="rounded bg-neutral-900 px-5 py-2.5 text-white">{{ __('install.continue') }}</button>
    </form>
@endsection

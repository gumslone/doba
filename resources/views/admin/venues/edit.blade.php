@extends('admin.layout', ['title' => __('admin.venues')])

@section('content')
    @php use App\Models\Venue; @endphp

    <h1 class="mb-6 text-2xl font-semibold">
        {{ $venue->exists ? ($venue->t('name') ?? $venue->code) : __('admin.new_venue') }}
    </h1>

    <form method="POST" action="{{ $venue->exists ? '/admin/venues/'.$venue->id : '/admin/venues' }}" class="space-y-6">
        @csrf
        @if ($venue->exists) @method('PUT') @endif

        <div class="grid gap-4 rounded border border-neutral-200 bg-white p-4 sm:grid-cols-3">
            <div>
                <label for="code" class="block text-sm font-medium">{{ __('admin.code') }}</label>
                <input id="code" name="code" required maxlength="64" value="{{ old('code', $venue->code) }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2 font-mono uppercase">
            </div>
            <div>
                <label for="type" class="block text-sm font-medium">{{ __('admin.venue_type') }}</label>
                <select id="type" name="type" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                    @foreach (Venue::TYPES as $type)
                        <option value="{{ $type }}" @selected(old('type', $venue->type) === $type)>{{ __('menu.type_'.$type) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="phone" class="block text-sm font-medium">{{ __('admin.phone') }}</label>
                <input id="phone" name="phone" value="{{ old('phone', $venue->phone) }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
            <div>
                <label for="seats" class="block text-sm font-medium">{{ __('admin.seats') }}</label>
                <input id="seats" name="seats" type="number" min="1" value="{{ old('seats', $venue->seats) }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
            <div>
                <label for="price_range" class="block text-sm font-medium">{{ __('admin.price_range') }}</label>
                <input id="price_range" name="price_range" maxlength="8" placeholder="€€"
                       value="{{ old('price_range', $venue->price_range) }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
            <div class="flex items-end gap-4 pb-2">
                <label class="flex items-center gap-2 text-sm">
                    <input type="hidden" name="reservations" value="0">
                    <input type="checkbox" name="reservations" value="1" @checked(old('reservations', $venue->reservations))>
                    {{ __('admin.reservations') }}
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $venue->is_active ?? true))>
                    {{ __('admin.active') }}
                </label>
            </div>
        </div>

        <fieldset class="rounded border border-neutral-200 bg-white p-4">
            <legend class="px-1 text-sm font-medium">{{ __('menu.opening_hours') }}</legend>
            <p class="mb-3 text-xs text-neutral-500">{{ __('admin.hours_hint') }}</p>

            <div class="space-y-2">
                @foreach (Venue::DAYS as $day)
                    @php $periods = old("hours.$day", $venue->opening_hours[$day] ?? []); @endphp
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="w-28 text-sm">{{ __('menu.day_'.$day) }}</span>
                        {{-- Two periods per day: lunch and dinner is the shape
                             a kitchen actually works in. --}}
                        @foreach ([0, 1] as $i)
                            <span class="flex items-center gap-1">
                                <input type="time" name="hours[{{ $day }}][{{ $i }}][from]"
                                       value="{{ is_array($periods) ? ($periods[$i]['from'] ?? $periods[$i][0] ?? '') : '' }}"
                                       class="rounded border border-neutral-300 px-2 py-1 text-sm">
                                <span class="text-neutral-400">–</span>
                                <input type="time" name="hours[{{ $day }}][{{ $i }}][to]"
                                       value="{{ is_array($periods) ? ($periods[$i]['to'] ?? $periods[$i][1] ?? '') : '' }}"
                                       class="rounded border border-neutral-300 px-2 py-1 text-sm">
                            </span>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </fieldset>

        @include('admin.partials.locale-tabs', ['fieldsView' => 'admin.venues.fields'])

        <button type="submit" class="rounded bg-neutral-900 px-6 py-2.5 text-white">{{ __('admin.save') }}</button>
    </form>

    @if ($venue->exists)
        <h2 class="mb-4 mt-12 text-xl font-semibold">{{ __('menu.card') }}</h2>

        <form method="POST" action="/admin/venues/{{ $venue->id }}/sections" class="mb-8 flex flex-wrap items-end gap-3">
            @csrf
            <div>
                <label for="section_code" class="block text-sm font-medium">{{ __('admin.code') }}</label>
                <input id="section_code" name="code" required maxlength="64" placeholder="STARTERS"
                       class="mt-1 rounded border border-neutral-300 px-3 py-2 font-mono uppercase">
            </div>
            <div>
                <label for="section_name" class="block text-sm font-medium">{{ __('admin.name') }}</label>
                <input id="section_name" name="name" required maxlength="255" placeholder="{{ __('admin.section_name_hint') }}"
                       class="mt-1 rounded border border-neutral-300 px-3 py-2">
            </div>
            <button type="submit" class="rounded border border-neutral-300 px-4 py-2 text-sm hover:bg-neutral-50">
                {{ __('admin.add_section') }}
            </button>
        </form>

        @if ($venue->sections->isEmpty())
            <p class="text-neutral-500">{{ __('admin.no_sections') }}</p>
        @else
            <form method="POST" action="/admin/venues/{{ $venue->id }}/dishes" class="space-y-8">
                @csrf

                @foreach ($venue->sections as $section)
                    <div class="rounded border border-neutral-200 bg-white p-4">
                        <div class="mb-4 flex items-center justify-between">
                            <h3 class="font-medium">
                                {{ $section->t('name') ?? $section->code }}
                                <span class="font-mono text-xs text-neutral-400">{{ $section->code }}</span>
                            </h3>
                        </div>

                        <div class="space-y-4">
                            @foreach ($section->dishes->push(new App\Models\Dish(['menu_section_id' => $section->id])) as $i => $dish)
                                @php $key = $dish->exists ? $dish->id : 'new-'.$section->id.'-'.$i; @endphp

                                <div class="grid gap-3 border-t border-neutral-100 pt-4 first:border-0 first:pt-0 sm:grid-cols-[2fr_1fr]">
                                    <div class="space-y-2">
                                        <input type="hidden" name="dishes[{{ $key }}][section]" value="{{ $section->id }}">
                                        <input type="text" name="dishes[{{ $key }}][name]"
                                               value="{{ $dish->exists ? $dish->t('name', null, false) : '' }}"
                                               placeholder="{{ $dish->exists ? '' : __('admin.new_dish') }}"
                                               class="w-full rounded border border-neutral-300 px-3 py-2">
                                        <textarea name="dishes[{{ $key }}][description]" rows="2"
                                                  placeholder="{{ __('admin.dish_description') }}"
                                                  class="w-full rounded border border-neutral-300 px-3 py-2 text-sm">{{ $dish->exists ? $dish->t('description', null, false) : '' }}</textarea>

                                        <details class="text-sm">
                                            <summary class="cursor-pointer text-neutral-600">{{ __('admin.allergens_diets') }}</summary>
                                            <div class="mt-2 grid gap-3 sm:grid-cols-2">
                                                <div>
                                                    <p class="mb-1 text-xs font-medium uppercase tracking-wide text-neutral-500">{{ __('menu.allergen_key') }}</p>
                                                    @foreach ($allergens as $allergen)
                                                        <label class="mr-3 inline-flex items-center gap-1 text-xs">
                                                            <input type="checkbox" name="dishes[{{ $key }}][allergens][]" value="{{ $allergen->value }}"
                                                                   @checked(in_array($allergen->value, $dish->allergens ?? [], true))>
                                                            {{ $allergen->number() }} {{ __($allergen->label()) }}
                                                        </label>
                                                    @endforeach
                                                </div>
                                                <div>
                                                    <p class="mb-1 text-xs font-medium uppercase tracking-wide text-neutral-500">{{ __('admin.diets') }}</p>
                                                    @foreach ($diets as $diet)
                                                        <label class="mr-3 inline-flex items-center gap-1 text-xs">
                                                            <input type="checkbox" name="dishes[{{ $key }}][diets][]" value="{{ $diet->value }}"
                                                                   @checked(in_array($diet->value, $dish->diets ?? [], true))>
                                                            {{ __($diet->label()) }}
                                                        </label>
                                                    @endforeach
                                                </div>
                                            </div>
                                        </details>
                                    </div>

                                    <div class="space-y-2">
                                        <input type="number" step="0.01" min="0" name="dishes[{{ $key }}][price]"
                                               value="{{ $dish->price === null ? '' : number_format($dish->price / 100, 2, '.', '') }}"
                                               placeholder="{{ __('menu.market_price') }}"
                                               class="w-full rounded border border-neutral-300 px-3 py-2">
                                        <input type="text" name="dishes[{{ $key }}][unit]" value="{{ $dish->unit }}"
                                               placeholder="{{ __('admin.dish_unit') }}"
                                               class="w-full rounded border border-neutral-300 px-3 py-2 text-sm">

                                        <label class="flex items-center gap-2 text-sm">
                                            <input type="hidden" name="dishes[{{ $key }}][is_available]" value="0">
                                            <input type="checkbox" name="dishes[{{ $key }}][is_available]" value="1"
                                                   @checked($dish->exists ? $dish->is_available : true)>
                                            {{ __('admin.available') }}
                                        </label>
                                        <label class="flex items-center gap-2 text-sm">
                                            <input type="hidden" name="dishes[{{ $key }}][is_signature]" value="0">
                                            <input type="checkbox" name="dishes[{{ $key }}][is_signature]" value="1"
                                                   @checked($dish->is_signature)>
                                            {{ __('menu.signature') }}
                                        </label>
                                        @if ($dish->exists)
                                            <label class="flex items-center gap-2 text-sm text-red-700">
                                                <input type="checkbox" name="dishes[{{ $key }}][delete]" value="1">
                                                {{ __('admin.delete') }}
                                            </label>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                <button type="submit" class="rounded bg-neutral-900 px-6 py-2.5 text-white">{{ __('admin.save_card') }}</button>
            </form>

            <div class="mt-6 flex flex-wrap gap-3">
                @foreach ($venue->sections as $section)
                    <form method="POST" action="/admin/venues/{{ $venue->id }}/sections/{{ $section->id }}"
                          onsubmit="return confirm('{{ __('admin.confirm_delete') }}')">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-sm text-red-600 hover:underline">
                            {{ __('admin.delete_section', ['name' => $section->t('name') ?? $section->code]) }}
                        </button>
                    </form>
                @endforeach
            </div>
        @endif
    @endif
@endsection

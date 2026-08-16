@extends('admin.layout', ['title' => __('admin.extras')])

@section('content')
    @php use App\Enums\AppliesPer; @endphp

    <h1 class="mb-6 text-2xl font-semibold">
        {{ $extra->exists ? ($extra->t('name') ?? $extra->code) : __('admin.new_extra') }}
    </h1>

    <form method="POST" action="{{ $extra->exists ? '/admin/extras/'.$extra->id : '/admin/extras' }}" class="space-y-6">
        @csrf
        @if ($extra->exists) @method('PUT') @endif

        <div class="grid gap-4 rounded border border-neutral-200 bg-white p-4 sm:grid-cols-3">
            <div>
                <label for="code" class="block text-sm font-medium">{{ __('admin.code') }}</label>
                <input type="text" id="code" name="code" required value="{{ old('code', $extra->code) }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2 font-mono text-sm">
            </div>
            <div>
                <label for="price" class="block text-sm font-medium">{{ __('admin.price') }} ({{ config('doba.currency') }})</label>
                <input type="number" id="price" name="price" step="0.01" min="0" required
                       value="{{ old('price', $extra->exists ? number_format($extra->price / 100, 2, '.', '') : '0.00') }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
            <div>
                <label for="applies_per" class="block text-sm font-medium">{{ __('admin.applies_per') }}</label>
                <select id="applies_per" name="applies_per" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                    @foreach (AppliesPer::cases() as $case)
                        <option value="{{ $case->value }}"
                            @selected(old('applies_per', $extra->applies_per?->value) === $case->value)>
                            {{ __($case->label()) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="tax_rate" class="block text-sm font-medium">{{ __('admin.tax_rate') }}</label>
                <input type="number" id="tax_rate" name="tax_rate" min="0" max="10000" required
                       value="{{ old('tax_rate', $extra->tax_rate ?? 1900) }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
            <div>
                <label for="max_quantity" class="block text-sm font-medium">{{ __('admin.max_quantity') }}</label>
                <input type="number" id="max_quantity" name="max_quantity" min="1" max="99" required
                       value="{{ old('max_quantity', $extra->max_quantity ?? 1) }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
            <div>
                <label for="sort_order" class="block text-sm font-medium">{{ __('admin.sort_order') }}</label>
                <input type="number" id="sort_order" name="sort_order" min="0"
                       value="{{ old('sort_order', $extra->sort_order ?? 0) }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
            <div class="flex items-end gap-4 pb-2 sm:col-span-3">
                <label class="flex items-center gap-2 text-sm">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $extra->is_active ?? true))>
                    {{ __('admin.active') }}
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="hidden" name="is_included" value="0">
                    <input type="checkbox" name="is_included" value="1" @checked(old('is_included', $extra->is_included))>
                    {{ __('admin.is_included') }}
                </label>
            </div>
        </div>

        <fieldset class="rounded border border-neutral-200 bg-white p-4">
            <legend class="px-1 text-sm font-medium">{{ __('admin.room_types') }}</legend>
            <p class="mb-3 text-xs text-neutral-500">{{ __('admin.room_types_hint') }}</p>

            @php $selected = old('room_type_ids', $extra->exists ? $extra->roomTypes->pluck('id')->all() : []); @endphp

            <div class="grid gap-2 sm:grid-cols-3">
                @foreach ($roomTypes as $roomType)
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="room_type_ids[]" value="{{ $roomType->id }}"
                               @checked(in_array($roomType->id, (array) $selected))>
                        {{ $roomType->t('name') ?? $roomType->code }}
                    </label>
                @endforeach
            </div>
        </fieldset>

        @include('admin.partials.locale-tabs', ['fieldsView' => 'admin.extras.fields'])

        <button type="submit" class="rounded bg-neutral-900 px-6 py-2.5 text-white">{{ __('admin.save') }}</button>
    </form>
@endsection

@extends('admin.layout', ['title' => $code->exists ? $code->code : __('admin.new_promo_code')])

@section('content')
    @php use App\Enums\DiscountType; @endphp

    <h1 class="mb-6 text-2xl font-semibold">{{ $code->exists ? $code->code : __('admin.new_promo_code') }}</h1>

    <form method="POST" action="{{ $code->exists ? '/admin/promo-codes/'.$code->id : '/admin/promo-codes' }}"
          class="max-w-2xl space-y-6">
        @csrf
        @if ($code->exists) @method('PUT') @endif

        <div class="grid gap-4 rounded border border-neutral-200 bg-white p-4 sm:grid-cols-2">
            <div>
                <label for="code" class="block text-sm font-medium">{{ __('admin.code') }}</label>
                <input id="code" name="code" required maxlength="32" value="{{ old('code', $code->code) }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2 font-mono uppercase">
                <p class="mt-1 text-xs text-neutral-500">{{ __('admin.code_hint') }}</p>
            </div>

            <div>
                <label for="discount_type" class="block text-sm font-medium">{{ __('admin.discount_type') }}</label>
                <select id="discount_type" name="discount_type" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                    @foreach (DiscountType::cases() as $type)
                        <option value="{{ $type->value }}" @selected(old('discount_type', $code->discount_type?->value) === $type->value)>
                            {{ __($type->label()) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="sm:col-span-2">
                <label for="value" class="block text-sm font-medium">{{ __('admin.value') }}</label>
                <input id="value" name="value" type="number" min="1" required
                       value="{{ old('value', $code->discount_type === DiscountType::Percent ? (int) ($code->value / 100) : $code->value) }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                <p class="mt-1 text-xs text-neutral-500">{{ __('admin.value_hint') }}</p>
            </div>
        </div>

        <fieldset class="grid gap-4 rounded border border-neutral-200 bg-white p-4 sm:grid-cols-2">
            <legend class="px-1 text-sm font-medium">{{ __('admin.promo_conditions') }}</legend>

            @foreach ([
                'min_nights' => ['number', __('admin.promo_min_nights_label')],
                'min_total' => ['number', __('admin.promo_min_total_label')],
                'valid_from' => ['date', __('admin.promo_valid_from')],
                'valid_to' => ['date', __('admin.promo_valid_to')],
                'stay_from' => ['date', __('admin.promo_stay_from')],
                'stay_to' => ['date', __('admin.promo_stay_to')],
                'usage_limit' => ['number', __('admin.promo_usage_limit')],
                'per_guest_limit' => ['number', __('admin.promo_per_guest_limit')],
            ] as $field => [$type, $label])
                <div>
                    <label for="{{ $field }}" class="block text-sm font-medium">{{ $label }}</label>
                    <input id="{{ $field }}" name="{{ $field }}" type="{{ $type }}"
                           value="{{ old($field, $type === 'date' ? $code->{$field}?->toDateString() : $code->{$field}) }}"
                           class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                </div>
            @endforeach

            <p class="text-xs text-neutral-500 sm:col-span-2">{{ __('admin.promo_conditions_hint') }}</p>
        </fieldset>

        <fieldset class="rounded border border-neutral-200 bg-white p-4">
            <legend class="px-1 text-sm font-medium">{{ __('admin.room_types') }}</legend>
            <p class="mb-3 text-xs text-neutral-500">{{ __('admin.promo_room_types_hint') }}</p>

            <div class="grid gap-2 sm:grid-cols-2">
                @foreach ($roomTypes as $roomType)
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="room_type_ids[]" value="{{ $roomType['id'] }}"
                               @checked(in_array($roomType['id'], old('room_type_ids', $code->room_type_ids ?? []), true))>
                        {{ $roomType['label'] }}
                    </label>
                @endforeach
            </div>
        </fieldset>

        <label class="flex items-center gap-2">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $code->is_active ?? true))>
            <span class="text-sm">{{ __('admin.active') }}</span>
        </label>

        @if ($errors->any())
            <ul class="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                @foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach
            </ul>
        @endif

        <div class="flex gap-3">
            <button type="submit" class="rounded bg-neutral-900 px-5 py-2.5 text-white">{{ __('admin.save') }}</button>
            <a href="/admin/promo-codes" class="px-5 py-2.5 text-neutral-600 hover:underline">{{ __('admin.cancel') }}</a>
        </div>
    </form>
@endsection

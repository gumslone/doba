@extends('admin.layout', ['title' => __('admin.rate_plans')])

@section('content')
    @php
        use App\Enums\AdjustmentType;
        use App\Http\Controllers\Admin\AdminRatePlanController;

        // Stored in basis points / minor units; shown the way a human says
        // it ("-12" for -12%, "-15.00" for -€15).
        $adjustmentDisplay = $plan->exists
            ? rtrim(rtrim(number_format($plan->adjustment_value / 100, 2, '.', ''), '0'), '.')
            : '0';
    @endphp

    <h1 class="mb-6 text-2xl font-semibold">
        {{ $plan->exists ? ($plan->t('name') ?? $plan->code) : __('admin.new_rate_plan') }}
    </h1>

    <form method="POST" action="{{ $plan->exists ? '/admin/rate-plans/'.$plan->id : '/admin/rate-plans' }}" class="space-y-6">
        @csrf
        @if ($plan->exists) @method('PUT') @endif

        <div class="grid gap-4 rounded border border-neutral-200 bg-white p-4 sm:grid-cols-4">
            <div>
                <label for="code" class="block text-sm font-medium">{{ __('admin.code') }}</label>
                <input type="text" id="code" name="code" required value="{{ old('code', $plan->code) }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2 font-mono text-sm">
            </div>
            <div>
                <label for="type" class="block text-sm font-medium">{{ __('admin.plan_type') }}</label>
                <select id="type" name="type" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                    @foreach (AdminRatePlanController::TYPES as $type)
                        <option value="{{ $type }}" @selected(old('type', $plan->type) === $type)>
                            {{ __('admin.plan_type_'.$type) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="adjustment_type" class="block text-sm font-medium">{{ __('admin.adjustment') }}</label>
                <select id="adjustment_type" name="adjustment_type" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                    @foreach (AdjustmentType::cases() as $case)
                        <option value="{{ $case->value }}"
                            @selected(old('adjustment_type', $plan->adjustment_type?->value) === $case->value)>
                            {{ __('admin.adjustment_'.$case->value) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="adjustment_value" class="block text-sm font-medium">{{ __('admin.adjustment_value') }}</label>
                <input type="number" id="adjustment_value" name="adjustment_value" step="0.01" required
                       value="{{ old('adjustment_value', $adjustmentDisplay) }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                <p class="mt-1 text-xs text-neutral-500">{{ __('admin.adjustment_hint') }}</p>
            </div>
        </div>

        <fieldset class="rounded border border-neutral-200 bg-white p-4">
            <legend class="px-1 text-sm font-medium">{{ __('admin.eligibility') }}</legend>
            <p class="mb-3 text-xs text-neutral-500">{{ __('admin.eligibility_hint') }}</p>

            <div class="grid gap-4 sm:grid-cols-4">
                @foreach ([
                    'min_nights' => __('admin.min_nights_field'),
                    'max_nights' => __('admin.max_nights_field'),
                    'min_days_before_arrival' => __('admin.min_days_ahead'),
                    'max_days_before_arrival' => __('admin.max_days_ahead'),
                ] as $field => $label)
                    <div>
                        <label for="{{ $field }}" class="block text-sm font-medium">{{ $label }}</label>
                        <input type="number" id="{{ $field }}" name="{{ $field }}" min="0" placeholder="—"
                               value="{{ old($field, $plan->{$field}) }}"
                               class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                    </div>
                @endforeach

                <div>
                    <label for="valid_from" class="block text-sm font-medium">{{ __('admin.valid_from') }}</label>
                    <input type="date" id="valid_from" name="valid_from"
                           value="{{ old('valid_from', $plan->valid_from?->toDateString()) }}"
                           class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                </div>
                <div>
                    <label for="valid_to" class="block text-sm font-medium">{{ __('admin.valid_to') }}</label>
                    <input type="date" id="valid_to" name="valid_to"
                           value="{{ old('valid_to', $plan->valid_to?->toDateString()) }}"
                           class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                </div>
                <div class="sm:col-span-2">
                    <p class="text-xs text-neutral-500">{{ __('admin.validity_hint') }}</p>
                </div>
            </div>
        </fieldset>

        <div class="grid gap-4 rounded border border-neutral-200 bg-white p-4 sm:grid-cols-4">
            <div>
                <label for="cancellation_hours" class="block text-sm font-medium">{{ __('admin.cancellation_hours') }}</label>
                <input type="number" id="cancellation_hours" name="cancellation_hours" min="0" required
                       value="{{ old('cancellation_hours', $plan->cancellation_hours ?? 48) }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
            <div>
                <label for="priority" class="block text-sm font-medium">{{ __('admin.sort_order') }}</label>
                <input type="number" id="priority" name="priority" min="0"
                       value="{{ old('priority', $plan->priority ?? 0) }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
            <div class="flex items-end gap-4 pb-2 sm:col-span-2">
                <label class="flex items-center gap-2 text-sm">
                    <input type="hidden" name="refundable" value="0">
                    <input type="checkbox" name="refundable" value="1" @checked(old('refundable', $plan->refundable ?? true))>
                    {{ __('admin.refundable') }}
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="hidden" name="includes_breakfast" value="0">
                    <input type="checkbox" name="includes_breakfast" value="1" @checked(old('includes_breakfast', $plan->includes_breakfast))>
                    {{ __('booking.incl_breakfast') }}
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $plan->is_active ?? true))>
                    {{ __('admin.active') }}
                </label>
            </div>
        </div>

        <fieldset class="rounded border border-neutral-200 bg-white p-4">
            <legend class="px-1 text-sm font-medium">{{ __('admin.room_types') }}</legend>
            <p class="mb-3 text-xs text-neutral-500">{{ __('admin.room_types_hint') }}</p>

            @php $selected = old('room_type_ids', $plan->exists ? $plan->roomTypes->pluck('id')->all() : []); @endphp

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

        @include('admin.partials.locale-tabs', ['fieldsView' => 'admin.rate-plans.fields'])

        <button type="submit" class="rounded bg-neutral-900 px-6 py-2.5 text-white">{{ __('admin.save') }}</button>
    </form>
@endsection

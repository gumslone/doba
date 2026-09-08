@extends('admin.layout', ['title' => $booking->reference])

@section('content')
    @php use App\Support\Money; @endphp

    <p class="mb-4 text-sm"><a href="/admin/front-desk" class="text-neutral-500 hover:underline">&larr; {{ __('admin.front_desk') }}</a></p>

    <div class="mb-6 flex flex-wrap items-baseline justify-between gap-3">
        <h1 class="text-2xl font-semibold">
            <span class="font-mono">{{ $booking->reference }}</span>
            <span class="ml-2 rounded bg-neutral-100 px-2 py-0.5 text-sm font-normal">{{ __('admin.status_'.$booking->status->value) }}</span>
        </h1>
        <div class="text-sm text-neutral-600">
            <a href="/admin/guests/{{ $booking->guest_id }}" class="underline">{{ $booking->guest?->last_name }}, {{ $booking->guest?->first_name }}</a>
            · {{ __('admin.source_'.$booking->source) ?: $booking->source }}
        </div>
    </div>

    @if (session('saved'))
        <p class="mb-6 rounded border border-green-200 bg-green-50 p-4 text-green-900">{{ session('saved') }}</p>
    @endif
    @if ($errors->any())
        <p class="mb-6 rounded border border-red-200 bg-red-50 p-4 text-red-900">{{ $errors->first() }}</p>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <form method="POST" action="/admin/bookings/{{ $booking->id }}" class="space-y-5 rounded border border-neutral-200 bg-white p-5 lg:col-span-2">
            @csrf @method('PUT')

            <h2 class="font-medium">{{ __('admin.booking_stay') }}</h2>
            @unless ($changeable)
                <p class="rounded border border-neutral-200 bg-neutral-50 p-3 text-sm text-neutral-600">{{ __('admin.booking_not_changeable') }}</p>
            @endunless

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="check_in" class="block text-sm font-medium">{{ __('admin.check_in') }}</label>
                    <input type="date" id="check_in" name="check_in" required @disabled(! $changeable) value="{{ old('check_in', $booking->check_in->toDateString()) }}" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                </div>
                <div>
                    <label for="check_out" class="block text-sm font-medium">{{ __('admin.check_out') }}</label>
                    <input type="date" id="check_out" name="check_out" required @disabled(! $changeable) value="{{ old('check_out', $booking->check_out->toDateString()) }}" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                </div>
                <div>
                    <label for="adults" class="block text-sm font-medium">{{ __('admin.adults') }}</label>
                    <input type="number" id="adults" name="adults" min="1" max="20" required @disabled(! $changeable) value="{{ old('adults', $booking->adults) }}" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                </div>
                <div>
                    <label for="children" class="block text-sm font-medium">{{ __('admin.children') }}</label>
                    <input type="number" id="children" name="children" min="0" max="20" @disabled(! $changeable) value="{{ old('children', $booking->children) }}" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                </div>
                <div>
                    <label for="arrival_time" class="block text-sm font-medium">{{ __('admin.arrival_time') }}</label>
                    <input type="time" id="arrival_time" name="arrival_time" value="{{ old('arrival_time', $booking->arrival_time) }}" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                </div>
            </div>

            <p class="text-xs text-neutral-500">{{ __('admin.booking_change_hint') }}</p>

            <div>
                <label for="guest_notes" class="block text-sm font-medium">{{ __('admin.booking_guest_notes') }}</label>
                <textarea id="guest_notes" name="guest_notes" rows="2" maxlength="2000" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">{{ old('guest_notes', $booking->guest_notes) }}</textarea>
            </div>
            <div>
                <label for="internal_notes" class="block text-sm font-medium">{{ __('admin.booking_internal_notes') }}</label>
                <textarea id="internal_notes" name="internal_notes" rows="2" maxlength="2000" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">{{ old('internal_notes', $booking->internal_notes) }}</textarea>
            </div>

            <button type="submit" class="rounded bg-neutral-900 px-5 py-2.5 text-white">{{ __('admin.save') }}</button>
        </form>

        <aside class="space-y-6">
            <section class="rounded border border-neutral-200 bg-white p-5 text-sm">
                <h2 class="font-medium">{{ __('admin.booking_money') }}</h2>
                <dl class="mt-3 space-y-1">
                    @foreach ($booking->rooms as $room)
                        <div class="flex justify-between"><dt class="text-neutral-500">{{ $room->roomType?->t('name') }}</dt><dd>{{ Money::format($room->price_total) }}</dd></div>
                    @endforeach
                    @foreach ($booking->extras as $extra)
                        <div class="flex justify-between"><dt class="text-neutral-500">{{ $extra->extra?->t('name') }} × {{ $extra->quantity }}</dt><dd>{{ Money::format($extra->total) }}</dd></div>
                    @endforeach
                    @if ($booking->cleaning_fee > 0)
                        <div class="flex justify-between"><dt class="text-neutral-500">{{ __('admin.cleaning_fee') }}</dt><dd>{{ Money::format($booking->cleaning_fee) }}</dd></div>
                    @endif
                    @if ($booking->city_tax > 0)
                        <div class="flex justify-between"><dt class="text-neutral-500">{{ __('admin.city_tax') }}</dt><dd>{{ Money::format($booking->city_tax) }}</dd></div>
                    @endif
                    @if ($booking->discount_total > 0)
                        <div class="flex justify-between"><dt class="text-neutral-500">{{ __('admin.discount') }}</dt><dd>−{{ Money::format($booking->discount_total) }}</dd></div>
                    @endif
                    <div class="flex justify-between border-t border-neutral-200 pt-1 font-medium"><dt>{{ __('admin.total') }}</dt><dd>{{ Money::format($booking->total) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-neutral-500">{{ __('admin.paid') }}</dt><dd>{{ Money::format($booking->paid_amount) }}</dd></div>
                    <div class="flex justify-between font-medium"><dt>{{ __('admin.balance') }}</dt><dd>{{ Money::format($booking->balance_due) }}</dd></div>
                </dl>
            </section>

            <section class="rounded border border-neutral-200 bg-white p-5 text-sm">
                <h2 class="font-medium">{{ __('admin.booking_history') }}</h2>
                <ul class="mt-3 space-y-1 text-xs text-neutral-600">
                    @foreach ($booking->statusHistory->sortByDesc('created_at') as $entry)
                        <li>{{ $entry->created_at?->format('Y-m-d H:i') }} — {{ $entry->to_status?->value ?? $entry->to_status }}@if ($entry->reason): {{ $entry->reason }}@endif</li>
                    @endforeach
                </ul>
            </section>
        </aside>
    </div>
@endsection

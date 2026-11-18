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

                @if ($booking->refund_due > 0)
                    {{-- The guest cancelled inside their free window and
                         has not been paid back. Nothing else in the admin
                         would ever say so. --}}
                    <p class="mt-4 rounded border border-amber-300 bg-amber-50 p-3 text-amber-900" role="alert">
                        {{ __('admin.booking_refund_owed', ['amount' => Money::format($booking->refund_due)]) }}
                    </p>
                @endif

                @php $ledger = $booking->payments->reject(fn ($p) => $p->status->value === 'pending' && $p->gateway === 'manual')->sortBy('id'); @endphp
                @if ($ledger->isNotEmpty())
                    <h3 class="mt-5 text-xs font-semibold uppercase tracking-wide text-neutral-500">{{ __('admin.booking_payments') }}</h3>
                    <ul class="mt-2 divide-y divide-neutral-100">
                        @foreach ($ledger as $payment)
                            <li class="py-2">
                                <div class="flex justify-between gap-3">
                                    <span>
                                        {{ $payment->type === 'refund' ? __('admin.payment_refund') : __('admin.payment_via_'.$payment->gateway) }}
                                        @if ($payment->gateway === 'desk' && ($method = $payment->payload['method'] ?? null))
                                            · {{ __('admin.payment_method_'.$method) }}
                                        @endif
                                        <span class="block text-xs text-neutral-500">
                                            {{ \App\Support\Hotel\Clock::local($payment->paid_at ?? $payment->refunded_at ?? $payment->created_at)?->format('Y-m-d H:i') }}
                                            · {{ __('admin.payment_status_'.$payment->status->value) }}
                                            @if ($note = $payment->payload['note'] ?? null) · {{ $note }} @endif
                                        </span>
                                    </span>
                                    <span class="shrink-0 font-medium">{{ $payment->type === 'refund' ? '−' : '' }}{{ Money::format($payment->amount, $payment->currency) }}</span>
                                </div>

                                @if (($refundable[$payment->id] ?? 0) > 0)
                                    <form method="POST" action="/admin/bookings/{{ $booking->id }}/payments/{{ $payment->id }}/refund"
                                          data-confirm="{{ __('admin.booking_refund_confirm') }}"
                                          class="mt-2 flex items-end gap-2">
                                        @csrf
                                        <label class="sr-only" for="refund_{{ $payment->id }}">{{ __('admin.booking_refund_amount') }}</label>
                                        <input type="number" id="refund_{{ $payment->id }}" name="amount" step="0.01" min="0.01"
                                               max="{{ number_format($refundable[$payment->id] / 100, 2, '.', '') }}"
                                               value="{{ number_format(min($refundable[$payment->id], max($booking->refund_due, 0) ?: $refundable[$payment->id]) / 100, 2, '.', '') }}"
                                               class="w-28 rounded border border-neutral-300 px-2 py-1">
                                        <button type="submit" class="rounded border border-neutral-300 px-3 py-1 hover:bg-neutral-50">{{ __('admin.booking_refund') }}</button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-2 text-xs text-neutral-500">{{ __('admin.booking_refund_hint') }}</p>
                @endif

                @if ($booking->balance_due > 0 && $booking->status->value !== 'cancelled')
                    <form method="POST" action="/admin/bookings/{{ $booking->id }}/payments" class="mt-5 space-y-3 border-t border-neutral-200 pt-4">
                        @csrf
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500">{{ __('admin.booking_record_payment') }}</h3>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label for="pay_amount" class="block text-xs font-medium">{{ __('admin.booking_payment_amount', ['currency' => $booking->currency]) }}</label>
                                <input type="number" id="pay_amount" name="amount" step="0.01" min="0.01" required
                                       max="{{ number_format($booking->balance_due / 100, 2, '.', '') }}"
                                       value="{{ old('amount', number_format($booking->balance_due / 100, 2, '.', '')) }}"
                                       class="mt-1 w-full rounded border border-neutral-300 px-2 py-1.5">
                            </div>
                            <div>
                                <label for="pay_method" class="block text-xs font-medium">{{ __('admin.booking_payment_method') }}</label>
                                <select id="pay_method" name="method" class="mt-1 w-full rounded border border-neutral-300 px-2 py-1.5">
                                    @foreach ($methods as $method)
                                        <option value="{{ $method }}" @selected(old('method') === $method)>{{ __('admin.payment_method_'.$method) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div>
                            <label for="pay_note" class="block text-xs font-medium">{{ __('admin.booking_payment_note') }}</label>
                            <input type="text" id="pay_note" name="note" maxlength="200" value="{{ old('note') }}" class="mt-1 w-full rounded border border-neutral-300 px-2 py-1.5">
                        </div>
                        <button type="submit" class="rounded bg-neutral-900 px-4 py-2 text-white">{{ __('admin.booking_record_payment') }}</button>
                    </form>
                @endif
            </section>

            @php
                $status = $booking->status;
                $canConfirm = $status->value === 'pending';
                $canNoShow = $status->value === 'confirmed' && $booking->check_in->toDateString() <= $today->toDateString();
                $canCancel = $status->canTransitionTo(\App\Enums\BookingStatus::Cancelled);
            @endphp
            @if ($canConfirm || $canNoShow || $canCancel)
                <section class="space-y-4 rounded border border-neutral-200 bg-white p-5 text-sm">
                    <h2 class="font-medium">{{ __('admin.booking_actions') }}</h2>

                    @if ($canConfirm)
                        <form method="POST" action="/admin/bookings/{{ $booking->id }}/confirm">
                            @csrf
                            @if ($holdExpiresAt)
                                <p class="mb-2 text-xs text-neutral-500">
                                    {{ __('admin.booking_pending_hint', ['time' => \App\Support\Hotel\Clock::local(\Carbon\CarbonImmutable::parse($holdExpiresAt))->translatedFormat('j M, H:i')]) }}
                                </p>
                            @endif
                            <button type="submit" class="rounded bg-neutral-900 px-4 py-2 text-white">{{ __('admin.booking_confirm') }}</button>
                        </form>
                    @endif

                    @if ($canNoShow)
                        <form method="POST" action="/admin/bookings/{{ $booking->id }}/no-show" data-confirm="{{ __('admin.booking_no_show_hint') }}">
                            @csrf
                            <button type="submit" class="rounded border border-neutral-300 px-4 py-2 hover:bg-neutral-50">{{ __('admin.booking_no_show') }}</button>
                            <p class="mt-1 text-xs text-neutral-500">{{ __('admin.booking_no_show_hint') }}</p>
                        </form>
                    @endif

                    @if ($canCancel)
                        <form method="POST" action="/admin/bookings/{{ $booking->id }}/cancel" data-confirm="{{ __('admin.booking_cancel_confirm') }}" class="space-y-2 border-t border-neutral-200 pt-4">
                            @csrf
                            <label for="cancel_reason" class="block text-xs font-medium">{{ __('admin.booking_cancel_reason') }}</label>
                            <input type="text" id="cancel_reason" name="reason" maxlength="200" class="w-full rounded border border-neutral-300 px-2 py-1.5">
                            <button type="submit" class="rounded border border-red-300 px-4 py-2 text-red-700 hover:bg-red-50">{{ __('admin.booking_cancel') }}</button>
                        </form>
                    @endif
                </section>
            @endif

            <section class="rounded border border-neutral-200 bg-white p-5 text-sm">
                <h2 class="font-medium">{{ __('admin.booking_history') }}</h2>
                <ul class="mt-3 space-y-1 text-xs text-neutral-600">
                    @foreach ($booking->statusHistory->sortByDesc('created_at') as $entry)
                        <li>{{ \App\Support\Hotel\Clock::local($entry->created_at)?->format('Y-m-d H:i') }} — {{ $entry->to_status?->value ?? $entry->to_status }}@if ($entry->reason): {{ $entry->reason }}@endif</li>
                    @endforeach
                </ul>
            </section>
        </aside>
    </div>
@endsection

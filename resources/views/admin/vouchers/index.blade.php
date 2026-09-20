@extends('admin.layout', ['title' => __('admin.vouchers')])

@section('content')
    @php
        use App\Models\GiftVoucher;
        use App\Support\Money;
        $field = 'mt-1 w-full rounded border border-neutral-300 px-3 py-2 text-sm';
    @endphp

    <div class="mb-6 flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">{{ __('admin.vouchers') }}</h1>
            <p class="mt-1 max-w-2xl text-sm text-neutral-600">{{ __('admin.vouchers_intro') }}</p>
        </div>
        <div class="text-sm text-neutral-600">{{ __('admin.vouchers_outstanding') }} <strong>{{ Money::format($outstanding) }}</strong></div>
    </div>

    @unless ($enabled)
        <p class="mb-6 rounded border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">{{ __('admin.vouchers_disabled') }}</p>
    @endunless
    @if ($errors->any())
        <p class="mb-6 rounded border border-red-200 bg-red-50 p-4 text-red-900">{{ $errors->first() }}</p>
    @endif

    @if ($pending->isNotEmpty())
        <section class="mb-8 rounded border border-amber-300 bg-white">
            <h2 class="border-b border-amber-200 px-5 py-4 font-medium">{{ __('admin.vouchers_pending') }} <span class="text-neutral-400">{{ $pending->count() }}</span></h2>
            <ul class="divide-y divide-neutral-100">
                @foreach ($pending as $voucher)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 text-sm">
                        <div>
                            <span class="font-mono font-medium">{{ $voucher->code }}</span>
                            · <strong>{{ Money::format($voucher->initial_amount, $voucher->currency) }}</strong>
                            <div class="text-neutral-500">{{ $voucher->buyer_name }} · {{ $voucher->buyer_email }} · {{ $voucher->created_at?->format('Y-m-d H:i') }}</div>
                        </div>
                        <div class="flex gap-2">
                            <form method="POST" action="/admin/vouchers/{{ $voucher->id }}/activate" data-confirm="{{ __('admin.voucher_activate_confirm', ['amount' => Money::format($voucher->initial_amount, $voucher->currency)]) }}">@csrf
                                <button type="submit" class="rounded bg-neutral-900 px-4 py-2 text-white">{{ __('admin.voucher_activate') }}</button>
                            </form>
                            <form method="POST" action="/admin/vouchers/{{ $voucher->id }}/void">@csrf
                                <button type="submit" class="rounded border border-neutral-300 px-4 py-2">{{ __('admin.voucher_void') }}</button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="mb-8 grid gap-6 lg:grid-cols-2">
        <form method="POST" action="/admin/vouchers" class="rounded border border-neutral-200 bg-white p-5">
            @csrf
            <h2 class="font-medium">{{ __('admin.voucher_new') }}</h2>
            <p class="mt-1 text-xs text-neutral-500">{{ __('admin.voucher_new_hint') }}</p>
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <div><label class="block text-xs text-neutral-500">{{ __('admin.voucher_amount') }} ({{ config('doba.currency') }})</label><input type="number" name="amount" min="1" step="1" required class="{{ $field }}"></div>
                <div><label class="block text-xs text-neutral-500">{{ __('admin.voucher_language') }}</label>
                    <select name="locale" class="{{ $field }}">@foreach ($locales as $l)<option value="{{ $l }}">{{ strtoupper($l) }}</option>@endforeach</select></div>
                <div><label class="block text-xs text-neutral-500">{{ __('admin.voucher_buyer') }}</label><input name="buyer_name" required maxlength="120" class="{{ $field }}"></div>
                <div><label class="block text-xs text-neutral-500">{{ __('admin.voucher_buyer_email') }}</label><input type="email" name="buyer_email" maxlength="254" class="{{ $field }}"></div>
                <div class="sm:col-span-2"><label class="block text-xs text-neutral-500">{{ __('admin.voucher_recipient') }}</label><input name="recipient_name" maxlength="120" class="{{ $field }}"></div>
                <div class="sm:col-span-2"><label class="block text-xs text-neutral-500">{{ __('admin.voucher_message') }}</label><textarea name="message" rows="2" maxlength="400" class="{{ $field }}"></textarea></div>
                <div class="sm:col-span-2"><label class="block text-xs text-neutral-500">{{ __('admin.voucher_note') }}</label><input name="internal_note" maxlength="500" class="{{ $field }}"></div>
            </div>
            <button type="submit" class="mt-4 rounded bg-neutral-900 px-5 py-2.5 text-sm text-white">{{ __('admin.voucher_issue') }}</button>
        </form>

        <form method="POST" action="/admin/vouchers/instructions" class="rounded border border-neutral-200 bg-white p-5">
            @csrf
            <h2 class="font-medium">{{ __('admin.voucher_instructions') }}</h2>
            <p class="mt-1 text-xs text-neutral-500">{{ __('admin.voucher_instructions_hint') }}</p>
            <textarea name="payment_instructions" rows="8" maxlength="2000" class="{{ $field }} mt-4 font-mono">{{ old('payment_instructions', $instructions) }}</textarea>
            <button type="submit" class="mt-4 rounded border border-neutral-300 px-5 py-2.5 text-sm">{{ __('admin.save') }}</button>
        </form>
    </div>

    <div class="overflow-x-auto rounded border border-neutral-200 bg-white">
        <table class="w-full text-sm">
            <thead class="border-b border-neutral-200 text-left text-neutral-500">
                <tr>
                    <th class="px-4 py-3">{{ __('admin.voucher_code') }}</th>
                    <th class="px-4 py-3">{{ __('admin.voucher_buyer') }}</th>
                    <th class="px-4 py-3">{{ __('admin.voucher_value') }}</th>
                    <th class="px-4 py-3">{{ __('admin.voucher_left') }}</th>
                    <th class="px-4 py-3">{{ __('admin.voucher_valid') }}</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($vouchers as $voucher)
                    <tr class="border-b border-neutral-100 align-top last:border-0">
                        <td class="px-4 py-3">
                            <a href="/admin/vouchers/{{ $voucher->id }}.pdf" target="_blank" class="font-mono font-medium hover:underline">{{ $voucher->code }}</a>
                            <span @class([
                                'ml-1 rounded px-1.5 py-0.5 text-xs',
                                'bg-green-100 text-green-800' => $voucher->status === GiftVoucher::ACTIVE && ! $voucher->isExpired(),
                                'bg-neutral-100 text-neutral-600' => $voucher->status === GiftVoucher::REDEEMED,
                                'bg-red-50 text-red-700' => $voucher->status === GiftVoucher::VOID || $voucher->isExpired(),
                            ])>{{ $voucher->isExpired() && $voucher->status === GiftVoucher::ACTIVE ? __('admin.voucher_status_expired') : __('admin.voucher_status_'.$voucher->status) }}</span>
                            @foreach ($voucher->redemptions as $redemption)
                                <div class="mt-1 text-xs text-neutral-500">
                                    −{{ Money::format($redemption->amount, $voucher->currency) }} · {{ $redemption->booking?->reference }} · {{ $redemption->created_at?->toDateString() }}
                                    @if ($redemption->restored_at) · {{ __('admin.voucher_restored') }} @endif
                                </div>
                            @endforeach
                        </td>
                        <td class="px-4 py-3">{{ $voucher->buyer_name }}<div class="text-xs text-neutral-500">{{ $voucher->recipient_name ? '→ '.$voucher->recipient_name : '' }} {{ $voucher->internal_note ? '· '.$voucher->internal_note : '' }}</div></td>
                        <td class="px-4 py-3">{{ Money::format($voucher->initial_amount, $voucher->currency) }}</td>
                        <td class="px-4 py-3 font-medium">{{ Money::format($voucher->balance, $voucher->currency) }}</td>
                        <td class="px-4 py-3 text-neutral-500">{{ $voucher->expires_on?->toDateString() ?? '—' }}</td>
                        <td class="px-4 py-3 text-right">
                            @if ($voucher->buyer_email && $voucher->status !== GiftVoucher::VOID)
                                <form method="POST" action="/admin/vouchers/{{ $voucher->id }}/resend" class="inline">@csrf<button type="submit" class="text-xs underline">{{ __('admin.voucher_resend') }}</button></form>
                            @endif
                            @if ($voucher->status === GiftVoucher::ACTIVE)
                                <form method="POST" action="/admin/vouchers/{{ $voucher->id }}/void" class="ml-2 inline" data-confirm="{{ __('admin.voucher_void_confirm') }}">@csrf<button type="submit" class="text-xs text-neutral-400 hover:text-red-600">{{ __('admin.voucher_void') }}</button></form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-neutral-500">{{ __('admin.vouchers_none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $vouchers->links() }}</div>
@endsection

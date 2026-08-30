@extends('admin.layout', ['title' => $guest->last_name.', '.$guest->first_name])

@section('content')
    @php use App\Support\Money; use App\Enums\BookingStatus; @endphp

    <p class="mb-4 text-sm"><a href="/admin/guests" class="text-neutral-500 hover:underline">&larr; {{ __('admin.guests') }}</a></p>

    <div class="mb-6 flex flex-wrap items-baseline justify-between gap-3">
        <h1 class="text-2xl font-semibold">
            {{ $guest->last_name }}, {{ $guest->first_name }}
            @if ($guest->stays_count >= 2 && ! $guest->isAnonymised())
                <span class="ml-2 rounded bg-green-100 px-2 py-0.5 text-sm font-normal text-green-800">
                    {{ trans_choice('admin.guest_nth_stay', $guest->stays_count, ['count' => $guest->stays_count]) }}
                </span>
            @endif
        </h1>
        <div class="text-sm text-neutral-600">
            {{ __('admin.guest_value') }}: <strong>{{ Money::format($guest->total_spent) }}</strong>
        </div>
    </div>

    @if (session('saved'))
        <p class="mb-6 rounded border border-green-200 bg-green-50 p-4 text-green-900">{{ session('saved') }}</p>
    @endif
    @if (session('update_error'))
        <p class="mb-6 rounded border border-red-200 bg-red-50 p-4 text-red-900">{{ session('update_error') }}</p>
    @endif

    @if ($guest->isAnonymised())
        <p class="mb-6 rounded border border-neutral-200 bg-neutral-50 p-4 text-sm text-neutral-600">
            {{ __('admin.guest_anonymised_notice', ['date' => $guest->anonymised_at->toDateString()]) }}
        </p>
    @else
        <section class="mb-8 grid gap-6 md:grid-cols-2">
            <div class="rounded border border-neutral-200 bg-white p-5">
                <h2 class="font-medium">{{ __('admin.guest_contact') }}</h2>
                <dl class="mt-3 space-y-1 text-sm">
                    <div class="flex gap-2"><dt class="w-24 text-neutral-500">{{ __('admin.guest_email') }}</dt><dd>{{ $guest->email }}</dd></div>
                    <div class="flex gap-2"><dt class="w-24 text-neutral-500">{{ __('admin.guest_phone') }}</dt><dd>{{ $guest->phone ?: '—' }}</dd></div>
                    <div class="flex gap-2"><dt class="w-24 text-neutral-500">{{ __('admin.guest_country') }}</dt><dd>{{ $guest->country ?: '—' }}</dd></div>
                    <div class="flex gap-2"><dt class="w-24 text-neutral-500">{{ __('admin.guest_locale') }}</dt><dd>{{ $guest->locale ?: '—' }}</dd></div>
                </dl>
            </div>

            <form method="POST" action="/admin/guests/{{ $guest->id }}" class="rounded border border-neutral-200 bg-white p-5">
                @csrf
                <h2 class="font-medium">{{ __('admin.guest_notes') }}</h2>
                <textarea name="notes" rows="4" class="mt-3 w-full rounded border border-neutral-300 px-3 py-2 text-sm"
                          placeholder="{{ __('admin.guest_notes_hint') }}">{{ $guest->notes }}</textarea>

                @if ($guest->marketing_consent)
                    <label class="mt-3 flex items-start gap-2 text-sm">
                        <input type="checkbox" name="marketing_consent" value="1" checked class="mt-0.5">
                        <span>
                            {{ __('admin.guest_consent_given') }}
                            <span class="block text-xs text-neutral-500">{{ __('admin.guest_consent_withdraw_hint') }}</span>
                        </span>
                    </label>
                @else
                    {{-- No checkbox to grant: consent is the guest's own
                         box at checkout, or it is nothing (§14). --}}
                    <p class="mt-3 text-sm text-neutral-500">{{ __('admin.guest_no_consent') }}</p>
                @endif

                <button type="submit" class="mt-4 rounded bg-neutral-900 px-4 py-2 text-sm text-white">{{ __('admin.save') }}</button>
            </form>
        </section>
    @endif

    <section class="mb-8 rounded border border-neutral-200 bg-white">
        <h2 class="border-b border-neutral-200 px-5 py-4 font-medium">{{ __('admin.guest_history') }}</h2>
        <table class="w-full text-sm">
            <tbody>
                @forelse ($bookings as $booking)
                    <tr class="border-b border-neutral-100 last:border-0">
                        <td class="px-5 py-3 font-mono text-xs">{{ $booking->reference }}</td>
                        <td class="px-5 py-3">{{ $booking->check_in->toDateString() }} → {{ $booking->check_out->toDateString() }}</td>
                        <td class="px-5 py-3">{{ $booking->rooms->map(fn ($r) => $r->roomType?->t('name'))->filter()->join(', ') }}</td>
                        <td class="px-5 py-3">{{ Money::format($booking->total) }}</td>
                        <td class="px-5 py-3">{{ __('admin.status_'.$booking->status->value) }}</td>
                        <td class="px-5 py-3 text-right">
                            @if ($booking->invoice)
                                <a class="underline" href="/admin/invoices/{{ $booking->invoice->id }}/download">{{ $booking->invoice->number }}</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td class="px-5 py-6 text-neutral-500">{{ __('admin.guest_no_stays') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <section class="rounded border border-neutral-200 bg-white p-5">
        <h2 class="font-medium">{{ __('admin.guest_privacy') }}</h2>
        <p class="mt-2 max-w-2xl text-sm text-neutral-600">{{ __('admin.guest_privacy_hint') }}</p>

        <div class="mt-4 flex flex-wrap items-end gap-6">
            <a href="/admin/guests/{{ $guest->id }}/export" class="rounded border border-neutral-300 px-4 py-2 text-sm">
                {{ __('admin.guest_export') }}
            </a>

            @unless ($guest->isAnonymised())
                <form method="POST" action="/admin/guests/{{ $guest->id }}/erase" class="flex items-end gap-2">
                    @csrf
                    <div>
                        <label for="confirm" class="block text-xs text-neutral-500">{{ __('admin.guest_erase_confirm') }}</label>
                        <input id="confirm" name="confirm" required autocomplete="off" placeholder="ERASE"
                               class="mt-1 rounded border border-neutral-300 px-3 py-1.5 font-mono text-sm">
                    </div>
                    <button type="submit" class="rounded border border-red-300 px-4 py-2 text-sm text-red-700">
                        {{ __('admin.guest_erase') }}
                    </button>
                </form>
            @endunless
        </div>
    </section>
@endsection

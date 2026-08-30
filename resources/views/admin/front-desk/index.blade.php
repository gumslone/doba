@extends('admin.layout', ['title' => __('admin.front_desk')])

@section('content')
    @php
        $room = fn ($booking) => $booking->rooms->first()?->roomType?->t('name') ?? '—';
        $nights = fn ($booking) => $booking->check_in->translatedFormat('j M').' – '.$booking->check_out->translatedFormat('j M');
    @endphp

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-semibold">{{ __('admin.front_desk') }}</h1>

        <form method="GET" class="flex items-center gap-2 text-sm">
            <label for="date" class="text-neutral-500">{{ __('admin.day') }}</label>
            <input type="date" id="date" name="date" value="{{ $date->toDateString() }}"
                   class="rounded border border-neutral-300 px-3 py-1.5">
            <button type="submit" class="rounded border border-neutral-300 px-3 py-1.5 hover:bg-neutral-50">
                {{ __('admin.show') }}
            </button>
        </form>
    </div>

    @if (session('desk_error'))
        <p class="mb-6 rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="alert">
            {{ session('desk_error') }}
        </p>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- Arriving --}}
        <section class="rounded border border-neutral-200 bg-white">
            <h2 class="border-b border-neutral-200 px-4 py-3 font-medium">
                {{ __('admin.arriving') }}
                <span class="text-neutral-400">{{ $arrivals->count() }}</span>
                <span class="ml-1 text-xs font-normal text-neutral-500">{{ __('admin.from_time', ['time' => $houseCheckin]) }}</span>
            </h2>

            <ul class="divide-y divide-neutral-100">
                @forelse ($arrivals as $booking)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm">
                        <div>
                            <strong>{{ $booking->guest?->last_name }}, {{ $booking->guest?->first_name }}</strong>
                            @if (($booking->guest?->stays_count ?? 0) >= 2)
                                {{-- The one fact that changes how the desk
                                     says hello. The count includes this
                                     stay: it was counted when confirmed. --}}
                                <span class="ml-1 rounded bg-green-100 px-1.5 py-0.5 text-xs text-green-800">
                                    {{ trans_choice('admin.guest_nth_stay', $booking->guest->stays_count, ['count' => $booking->guest->stays_count]) }}
                                </span>
                            @endif
                            <span class="ml-1 font-mono text-xs text-neutral-400">{{ $booking->reference }}</span>
                            <p class="text-neutral-500">
                                {{ $room($booking) }} · {{ $nights($booking) }} ·
                                {{ trans_choice('admin.guest_count', $booking->adults + $booking->children, ['count' => $booking->adults + $booking->children]) }}
                            </p>
                            <p class="mt-0.5">
                                @if ($booking->arrival_time)
                                    {{-- What the guest actually told us, which is
                                         the difference between holding a room and
                                         wondering about it. --}}
                                    <span class="rounded bg-neutral-900 px-1.5 py-0.5 text-xs text-white">
                                        {{ __('admin.arriving_at', ['time' => $booking->arrival_time]) }}
                                    </span>
                                @else
                                    <span class="text-xs text-neutral-400">{{ __('admin.no_arrival_time') }}</span>
                                @endif
                            </p>
                        </div>

                        <form method="POST" action="/admin/front-desk/{{ $booking->id }}/check-in">
                            @csrf
                            <button type="submit" class="rounded bg-neutral-900 px-3 py-1.5 text-white">
                                {{ __('admin.check_in') }}
                            </button>
                        </form>
                    </li>
                @empty
                    <li class="px-4 py-6 text-sm text-neutral-500">{{ __('admin.no_arrivals') }}</li>
                @endforelse
            </ul>
        </section>

        {{-- Leaving today --}}
        <section class="rounded border border-neutral-200 bg-white">
            <h2 class="border-b border-neutral-200 px-4 py-3 font-medium">
                {{ __('admin.departing') }}
                <span class="text-neutral-400">{{ $departures->count() }}</span>
                <span class="ml-1 text-xs font-normal text-neutral-500">{{ __('admin.until_time', ['time' => $houseCheckout]) }}</span>
            </h2>

            <ul class="divide-y divide-neutral-100">
                @forelse ($departures as $booking)
                    <li class="px-4 py-3 text-sm">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <strong>{{ $booking->guest?->last_name }}, {{ $booking->guest?->first_name }}</strong>
                                <span class="ml-1 font-mono text-xs text-neutral-400">{{ $booking->reference }}</span>
                                <p class="text-neutral-500">{{ $room($booking) }}</p>
                                <p class="mt-0.5 text-xs">
                                    {{ __('admin.leaving_by', ['time' => $booking->departureTime()]) }}
                                    @if ($booking->hasLateCheckout())
                                        <span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-amber-900">
                                            {{ __('admin.late_checkout') }}
                                        </span>
                                    @endif
                                </p>
                            </div>

                            <form method="POST" action="/admin/front-desk/{{ $booking->id }}/check-out">
                                @csrf
                                <button type="submit" class="rounded bg-neutral-900 px-3 py-1.5 text-white">
                                    {{ __('admin.check_out') }}
                                </button>
                            </form>
                        </div>

                        @include('admin.front-desk._late-checkout')

                    </li>
                @empty
                    <li class="px-4 py-6 text-sm text-neutral-500">{{ __('admin.no_departures') }}</li>
                @endforelse
            </ul>
        </section>

        {{-- Occupied --}}
        <section class="rounded border border-neutral-200 bg-white">
            <h2 class="border-b border-neutral-200 px-4 py-3 font-medium">
                {{ __('admin.in_house') }} <span class="text-neutral-400">{{ $inHouse->count() }}</span>
            </h2>

            <ul class="divide-y divide-neutral-100">
                @forelse ($inHouse as $booking)
                    <li class="px-4 py-3 text-sm">
                        <strong>{{ $booking->guest?->last_name }}, {{ $booking->guest?->first_name }}</strong>
                        <span class="ml-1 font-mono text-xs text-neutral-400">{{ $booking->reference }}</span>
                        <p class="text-neutral-500">
                            {{ $room($booking) }} · {{ $nights($booking) }}
                            @if ($booking->checked_in_at)
                                · {{ __('admin.arrived_at', ['time' => $booking->checked_in_at->translatedFormat('j M, H:i')]) }}
                            @endif
                        </p>
                        <p class="mt-0.5 text-xs text-neutral-500">
                            {{ __('admin.leaving_by', ['time' => $booking->departureTime()]) }}
                            @if ($booking->hasLateCheckout())
                                <span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-amber-900">
                                    {{ __('admin.late_checkout') }}
                                </span>
                            @endif
                        </p>

                        @include('admin.front-desk._late-checkout')
                    </li>
                @empty
                    <li class="px-4 py-6 text-sm text-neutral-500">{{ __('admin.nobody_in_house') }}</li>
                @endforelse
            </ul>
        </section>

        {{-- Gone --}}
        <section class="rounded border border-neutral-200 bg-white">
            <h2 class="border-b border-neutral-200 px-4 py-3 font-medium">
                {{ __('admin.checked_out_today') }} <span class="text-neutral-400">{{ $departed->count() }}</span>
                <span class="ml-1 text-xs font-normal text-neutral-500">{{ __('admin.rooms_free') }}</span>
            </h2>

            <ul class="divide-y divide-neutral-100">
                @forelse ($departed as $booking)
                    <li class="px-4 py-3 text-sm">
                        <strong>{{ $booking->guest?->last_name }}, {{ $booking->guest?->first_name }}</strong>
                        <p class="text-neutral-500">
                            {{ $room($booking) }} ·
                            {{ __('admin.left_at', ['time' => $booking->checked_out_at?->translatedFormat('H:i')]) }}
                        </p>
                    </li>
                @empty
                    <li class="px-4 py-6 text-sm text-neutral-500">{{ __('admin.nobody_left') }}</li>
                @endforelse
            </ul>
        </section>
    </div>
@endsection

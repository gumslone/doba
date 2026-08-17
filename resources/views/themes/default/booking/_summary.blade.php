{{-- The booking's money and dates, shared by the pay, confirmation and
     manage pages so all three can never disagree about a total. --}}
@php use App\Support\Money; @endphp

<dl class="space-y-2 text-sm">
    <div class="flex justify-between gap-4">
        <dt class="text-neutral-500">{{ __('booking.reference') }}</dt>
        <dd class="text-right font-mono font-medium">{{ $booking->reference }}</dd>
    </div>
    <div class="flex justify-between gap-4">
        <dt class="text-neutral-500">{{ __('booking.dates') }}</dt>
        <dd class="text-right">
            {{ $booking->check_in->translatedFormat('j M Y') }} –
            {{ $booking->check_out->translatedFormat('j M Y') }}
        </dd>
    </div>

    @foreach ($booking->rooms as $room)
        <div class="flex justify-between gap-4">
            <dt class="text-neutral-500">{{ $room->roomType?->t('name') ?? __('booking.room') }}</dt>
            <dd class="text-right">{{ Money::format($room->price_total) }}</dd>
        </div>
    @endforeach

    @foreach ($booking->extras as $bookingExtra)
        <div class="flex justify-between gap-4">
            <dt class="text-neutral-500">
                {{ $bookingExtra->extra?->t('name') }}
                @if ($bookingExtra->quantity > 1) × {{ $bookingExtra->quantity }} @endif
            </dt>
            <dd class="text-right">{{ Money::format($bookingExtra->total) }}</dd>
        </div>
    @endforeach

    @if ($booking->discount_total > 0)
        {{-- Shown on its own line, never folded into the room price: a
             guest who used a code is entitled to see what it took off. --}}
        <div class="flex justify-between gap-4">
            <dt class="text-neutral-500">
                {{ __('promo.discount') }}
                @if ($booking->promoCode) <span class="font-mono">{{ $booking->promoCode->code }}</span> @endif
            </dt>
            <dd class="text-right text-green-700">−{{ Money::format($booking->discount_total) }}</dd>
        </div>
    @endif

    <div class="flex justify-between gap-4 border-t border-neutral-200 pt-3 text-base">
        <dt class="font-medium">{{ __('booking.total') }}</dt>
        <dd class="text-right font-semibold">{{ Money::format($booking->total) }}</dd>
    </div>

    @if ($booking->paid_amount > 0)
        <div class="flex justify-between gap-4">
            <dt class="text-neutral-500">{{ __('booking.deposit') }}</dt>
            <dd class="text-right">{{ Money::format($booking->paid_amount) }}</dd>
        </div>
    @endif

    @if ($booking->balance_due > 0)
        <div class="flex justify-between gap-4">
            <dt class="text-neutral-500">{{ __('booking.balance') }}</dt>
            <dd class="text-right">{{ Money::format($booking->balance_due) }}</dd>
        </div>
    @endif
</dl>

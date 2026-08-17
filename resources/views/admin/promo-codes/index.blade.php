@extends('admin.layout', ['title' => __('admin.promo_codes')])

@section('content')
    @php use App\Support\Money; @endphp

    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">{{ __('admin.promo_codes') }}</h1>
        <a href="/admin/promo-codes/create" class="rounded bg-neutral-900 px-4 py-2 text-sm text-white">{{ __('admin.new_promo_code') }}</a>
    </div>

    <ul class="divide-y divide-neutral-200 rounded border border-neutral-200 bg-white">
        @forelse ($codes as $code)
            <li class="flex flex-wrap items-center justify-between gap-4 px-4 py-3">
                <div>
                    <a href="/admin/promo-codes/{{ $code->id }}/edit" class="font-mono font-medium hover:underline">{{ $code->code }}</a>
                    <p class="text-sm text-neutral-500">
                        @switch($code->discount_type)
                            @case(App\Enums\DiscountType::Percent) {{ $code->value / 100 }}% @break
                            @case(App\Enums\DiscountType::Fixed) {{ Money::format($code->value) }} @break
                            @default {{ trans_choice('admin.free_nights_count', $code->value, ['count' => $code->value]) }}
                        @endswitch
                        @if ($code->min_nights) · {{ __('admin.promo_min_nights', ['count' => $code->min_nights]) }} @endif
                        @if ($code->valid_to) · {{ __('admin.promo_until', ['date' => $code->valid_to->translatedFormat('j M Y')]) }} @endif
                        @unless ($code->is_active) · <span class="text-amber-600">{{ __('admin.draft') }}</span> @endunless
                    </p>
                </div>

                <div class="flex items-center gap-4">
                    {{-- What a hotelier actually wants to know: did it work? --}}
                    <p class="text-right text-sm">
                        <strong>{{ $code->active_redemptions_count }}</strong>
                        @if ($code->usage_limit) / {{ $code->usage_limit }} @endif
                        <span class="block text-neutral-500">
                            {{ __('admin.promo_given', ['amount' => Money::format((int) $code->discount_given)]) }}
                        </span>
                    </p>
                    <form method="POST" action="/admin/promo-codes/{{ $code->id }}"
                          onsubmit="return confirm('{{ __('admin.confirm_delete') }}')">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-sm text-red-600 hover:underline">{{ __('admin.delete') }}</button>
                    </form>
                </div>
            </li>
        @empty
            <li class="px-4 py-6 text-neutral-500">{{ __('admin.no_promo_codes') }}</li>
        @endforelse
    </ul>
@endsection

@extends('admin.layout', ['title' => __('admin.rate_plans')])

@section('content')
    @php
        use App\Enums\AdjustmentType;
        use App\Support\Money;
    @endphp

    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">{{ __('admin.rate_plans') }}</h1>
        <a href="/admin/rate-plans/create" class="rounded bg-neutral-900 px-4 py-2 text-sm text-white">
            {{ __('admin.new_rate_plan') }}
        </a>
    </div>

    <ul class="divide-y divide-neutral-200 rounded border border-neutral-200 bg-white">
        @forelse ($plans as $plan)
            <li class="flex items-center justify-between gap-4 px-4 py-3">
                <div>
                    <a href="/admin/rate-plans/{{ $plan->id }}/edit" class="font-medium hover:underline">
                        {{ $plan->t('name') ?? $plan->code }}
                    </a>
                    <p class="text-sm text-neutral-500">
                        {{ $plan->code }} ·
                        @if ($plan->adjustment_value === 0)
                            {{ __('admin.base_price') }}
                        @elseif ($plan->adjustment_type === AdjustmentType::Percent)
                            {{ $plan->adjustment_value > 0 ? '+' : '' }}{{ rtrim(rtrim(number_format($plan->adjustment_value / 100, 2), '0'), '.') }}%
                        @else
                            {{ $plan->adjustment_value > 0 ? '+' : '−' }}{{ Money::format(abs($plan->adjustment_value)) }}
                        @endif
                        ·
                        @if ($plan->refundable)
                            {{ __('booking.free_until', ['hours' => $plan->cancellation_hours]) }}
                        @else
                            {{ __('booking.non_refundable') }}
                        @endif
                        @unless ($plan->is_active) · <span class="text-amber-600">{{ __('admin.draft') }}</span> @endunless
                    </p>
                </div>

                <form method="POST" action="/admin/rate-plans/{{ $plan->id }}"
                      onsubmit="return confirm('{{ __('admin.confirm_delete') }}')">
                    @csrf @method('DELETE')
                    <button type="submit" class="text-sm text-red-600 hover:underline">{{ __('admin.delete') }}</button>
                </form>
            </li>
        @empty
            <li class="px-4 py-6 text-neutral-500">—</li>
        @endforelse
    </ul>
@endsection

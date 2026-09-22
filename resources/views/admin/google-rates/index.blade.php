@extends('admin.layout', ['title' => __('admin.google_rates')])

@section('content')
    @php use App\Support\Money; @endphp

    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">{{ __('admin.google_rates') }}</h1>
            <p class="mt-1 max-w-2xl text-sm text-neutral-600">{{ __('admin.google_rates_intro') }}</p>
        </div>
        <a href="/admin/google-rates/export" class="rounded border border-neutral-300 px-4 py-2 text-sm">{{ __('admin.export_csv') }}</a>
    </div>

    <section class="mb-6 rounded border border-neutral-200 bg-white p-5 text-sm">
        <h2 class="font-medium">{{ __('admin.google_rates_how') }}</h2>
        <ol class="mt-2 list-decimal space-y-1 pl-5 text-neutral-700">
            <li>{{ __('admin.google_rates_step_1') }}</li>
            <li>{{ __('admin.google_rates_step_2') }}
                <input readonly value="{{ $bookingUrl }}" data-select-on-click class="mt-1 block w-full max-w-xl rounded border border-neutral-300 bg-neutral-50 px-3 py-1.5 font-mono text-xs"></li>
            <li>{{ __('admin.google_rates_step_3', ['guests' => $guests]) }}</li>
        </ol>
    </section>

    <div class="overflow-x-auto rounded border border-neutral-200 bg-white">
        <table class="w-full text-sm">
            <thead class="border-b border-neutral-200 text-left text-neutral-500">
                <tr>
                    <th class="px-4 py-3">{{ __('admin.google_rates_date') }}</th>
                    <th class="px-4 py-3">{{ __('admin.google_rates_price') }}</th>
                    <th class="px-4 py-3">{{ __('admin.google_rates_room_price') }}</th>
                    <th class="px-4 py-3">{{ __('admin.room_type_name') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($days as $day)
                    <tr @class(['border-b border-neutral-100 last:border-0', 'bg-neutral-50' => $day['date']->isWeekend()])>
                        <td class="px-4 py-2 font-mono">{{ $day['date']->toDateString() }} <span class="text-neutral-400">{{ $day['date']->translatedFormat('D') }}</span></td>
                        @if ($day['price'] === null)
                            <td class="px-4 py-2 text-neutral-400" colspan="3">{{ __('admin.google_rates_sold_out') }}</td>
                        @else
                            <td class="px-4 py-2 font-medium">{{ Money::exact($day['total']) }}</td>
                            <td class="px-4 py-2 text-neutral-500">{{ Money::exact($day['price']) }}</td>
                            <td class="px-4 py-2 text-neutral-500">{{ $day['room_type'] }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection

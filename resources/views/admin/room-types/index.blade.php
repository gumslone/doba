@extends('admin.layout', ['title' => __('admin.room_types')])

@section('content')
    @php use App\Support\Money; @endphp
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">{{ __('admin.room_types') }}</h1>
            <p class="mt-1 max-w-2xl text-sm text-neutral-600">{{ __('admin.room_types_intro') }}</p>
        </div>
        <a href="/admin/room-types/create" class="rounded bg-neutral-900 px-4 py-2 text-sm text-white">{{ __('admin.room_type_new') }}</a>
    </div>

    @if (session('saved'))
        <p class="mb-6 rounded border border-green-200 bg-green-50 p-4 text-green-900">{{ session('saved') }}</p>
    @endif

    <div class="overflow-x-auto rounded border border-neutral-200 bg-white">
        <table class="w-full text-sm">
            <thead class="border-b border-neutral-200 text-left text-neutral-500">
                <tr>
                    <th class="px-4 py-3">{{ __('admin.room_type_name') }}</th>
                    <th class="px-4 py-3">{{ __('admin.room_type_max_occupancy') }}</th>
                    <th class="px-4 py-3">{{ __('admin.room_type_default_rate') }}</th>
                    <th class="px-4 py-3">{{ __('admin.units') }}</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($roomTypes as $type)
                    <tr class="border-b border-neutral-100 last:border-0">
                        <td class="px-4 py-3">
                            <a href="/admin/room-types/{{ $type->id }}/edit" class="font-medium hover:underline">{{ $type->t('name') ?? $type->code }}</a>
                            <span class="ml-1 font-mono text-xs text-neutral-400">{{ $type->code }}</span>
                            @if ($type->isApartment())
                                <span class="ml-1 rounded bg-amber-50 px-1.5 py-0.5 text-xs text-amber-800">{{ __('admin.room_type_kind_apartment') }}</span>
                            @endif
                            @unless ($type->is_active)
                                <span class="ml-1 rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-500">{{ __('admin.room_type_inactive') }}</span>
                            @endunless
                            <div class="text-xs text-neutral-500">
                                {{ $type->translations->pluck('locale')->map(fn ($l) => strtoupper($l))->join(' · ') }}
                            </div>
                        </td>
                        <td class="px-4 py-3">{{ $type->max_occupancy }}</td>
                        <td class="px-4 py-3">{{ Money::format($type->default_rate) }}</td>
                        <td class="px-4 py-3">{{ $type->total_units }} <span class="text-xs text-neutral-500">· {{ $type->rooms_count }} {{ __('admin.room_type_doors') }}</span></td>
                        <td class="px-4 py-3 text-right">
                            <a href="/admin/photos/room-type:{{ $type->id }}" class="text-xs underline">{{ __('admin.room_type_photos') }} ({{ $type->media->count() }})</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-neutral-500">{{ __('common.no_rooms_yet') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection

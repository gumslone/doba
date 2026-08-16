@extends('admin.layout', ['title' => __('admin.photos')])

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">{{ __('admin.photos') }}</h1>

    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ __('admin.galleries') }}</h2>
    <ul class="mb-8 divide-y divide-neutral-200 rounded border border-neutral-200 bg-white">
        @foreach ($galleries as $gallery)
            <li class="flex items-center justify-between px-4 py-3">
                <a href="/admin/photos/gallery:{{ $gallery->id }}" class="font-medium hover:underline">
                    {{ $gallery->t('name') ?? ucfirst($gallery->code) }}
                </a>
                <span class="text-sm text-neutral-500">{{ $gallery->media->count() }} {{ __('admin.photos_count') }}</span>
            </li>
        @endforeach
    </ul>

    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ __('admin.room_types') }}</h2>
    <ul class="divide-y divide-neutral-200 rounded border border-neutral-200 bg-white">
        @foreach ($roomTypes as $roomType)
            <li class="flex items-center justify-between px-4 py-3">
                <a href="/admin/photos/room-type:{{ $roomType->id }}" class="font-medium hover:underline">
                    {{ $roomType->t('name') ?? $roomType->code }}
                </a>
                <span class="text-sm text-neutral-500">{{ $roomType->media->count() }} {{ __('admin.photos_count') }}</span>
            </li>
        @endforeach
    </ul>
@endsection

@extends('layouts.app')

@section('content')
    <article class="mx-auto max-w-3xl px-4 py-12">
        <p class="text-sm font-medium" style="color: var(--doba-primary)">
            {{ $event->starts_at->translatedFormat('l, j F Y · H:i') }}
            @if ($event->ends_at && ! $event->ends_at->isSameDay($event->starts_at))
                – {{ $event->ends_at->translatedFormat('j F Y') }}
            @endif
        </p>

        <h1 class="mt-2 text-3xl font-semibold tracking-tight">{{ $event->t('title') }}</h1>

        @if ($event->location)
            <p class="mt-1 text-neutral-500">{{ $event->location }}</p>
        @endif

        <x-responsive-image
            :media="$event->media->first()"
            :eager="true"
            sizes="(max-width: 1024px) 100vw, 768px"
            class="mt-8 aspect-[16/9] w-full rounded-lg object-cover" />

        @if ($body = $event->t('body'))
            <div class="prose mt-8 max-w-none">{!! $body !!}</div>
        @elseif ($excerpt = $event->t('excerpt'))
            <p class="mt-8 text-lg text-neutral-700">{{ $excerpt }}</p>
        @endif
    </article>
@endsection
